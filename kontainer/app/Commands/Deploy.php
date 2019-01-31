<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Aws\Ecr\EcrClient as EcrClient;
use Aws\Ec2\Ec2Client as Ec2Client;
use Aws\Iam\IamClient as IamClient;
use Aws\AutoScaling\AutoScalingClient as AutoScalingClient;
use josegonzalez\Dotenv\Loader as Loader;
use Amp\Loop;
use Amp\Parallel\Worker;
use Amp\Promise;
use Aws\Exception\AwsException;
use Aws\Iam\Exception\IamException;

class Deploy extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'deploy {environment : Either qa or production} {namespace : The namespace. Usually the name of the app} {branch : The branch of the code that you wish to deploy} {build : The build number (usually increments)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a deployment';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $configEnv = '/Users/lawrencedudley/Desktop/config.env';

        // Configure environment variables
        if(!file_exists($configEnv)) {
            throw new \RuntimeException($configEnv . ' does not exist, required!');
        }
        $Loader = new Loader($configEnv);
        $Loader->parse()->putenv();

        // Grab the inputs
        $environment = $this->argument('environment');
        $branch = $this->argument('branch');
        $build = $this->argument('build');
        $namespace = $this->argument('namespace');
        $directory = getcwd();

        $awsOptions = [
            'region'            => 'eu-west-1',
            'version'           => '2015-09-21',
        ];

        // Operation summary
        $this->info('Creating a deployment for ' . $namespace  . ' on branch ' . $branch . ' on ' . $environment . ' from ' . $directory);

        // Set up the ECR repository
        // Check if it already exists:
        $ecr = new EcrClient($awsOptions);
        $repositoryExists = false;

        $repositories = $ecr->DescribeRepositories()['repositories'];

        foreach ($repositories as $key => $repository) {
            if ($repository['repositoryName'] == $namespace) {
                
                $repositoryExists = true;
                $this->info('ECR Repository ' . $namespace . ' already exists');
            
            }
        }

        // Create the repository if it doesn't exist
        if ($repositoryExists === false) {

            $this->info('Creating ECR Repository ' . $namespace);
            $ecr->CreateRepository([
                'repositoryName' => $namespace,
                'tags' => [
                    [
                        'Key' => 'namespace',
                        'Value' => $namespace
                    ]
                ]
            ]);

        }

        /* Get info about the repository now it definitely exists

        Supplies into repositoryInfo:
        Array
        (
            [repositoryArn] => arn:aws:ecr:eu-west-1:140358210270:repository/kontainer-test
            [registryId] => 140358210270
            [repositoryName] => kontainer-test
            [repositoryUri] => 140358210270.dkr.ecr.eu-west-1.amazonaws.com/kontainer-test
            [createdAt] => Aws\Api\DateTimeResult Object
                (
                    [date] => 2019-01-30 16:27:25.000000
                    [timezone_type] => 1
                    [timezone] => +00:00
                )
        
        )
        */

        $repositoryInfo = $ecr->DescribeRepositories(['repositoryNames' => [$namespace]])['repositories'][0];

        // Get a list of dockerFiles
        $dockerFiles = scandir($directory . '/kontainer/docker/');
        // Filter for anything beginning with a .
        foreach ($dockerFiles as $key => $dockerFile) {
            if(strpos($dockerFile, '.') === 0) {
                unset($dockerFiles[$key]);
            }
        }
        $dockerFiles = array_values($dockerFiles);

        function toTableOutput($array) {
            foreach ($array as $key => $value) {
                $return[$key] = array($value);
            }
            return $return;
        }

        $headers = ['Dockerfiles to Build and Push'];
        $this->table($headers, toTableOutput($dockerFiles));

        // Docker build - needs parallelising eventually

        function dockerfileBuild($dockerFile, $tag, $directory) {
            // Build
            $ecrLogin = exec('$(aws ecr get-login --no-include-email)');
            $dockerOutput = system("docker build --compress=true --file kontainer/docker/$dockerFile -t $tag $directory", $dockerBuild);
            if ($dockerBuild != 0) {
                throw new \RuntimeException("Docker build failed for $dockerFile - see logs above", 1);
            }
            // Push
            $dockerOutput .= system("docker push $tag", $dockerPush);
            if ($dockerPush != 0) {
                throw new \RuntimeException("Docker push failed for $dockerFile - see logs above", 1);
            }
            return($dockerOutput);
        }
        
        foreach ($dockerFiles as $key => $value) {
            dockerfileBuild($dockerFile, $repositoryInfo['repositoryUri'] . ':' . $dockerFile . '-' . $build, $directory);
        }

        // The docker images should exist at $repositoryInfo['repositoryUri']:$dockerFile-$build now

        // The AWS bit
        // Check if a security group with the name $namespace-$environment and $dockerFile-$namespace-$environment exists, if not, create them
        $awsOptions = [
            'region'            => 'eu-west-1',
            'version'           => '2016-11-15',
        ];

        $ec2 = new Ec2Client($awsOptions);

        function ensureSecurityGroup($securityGroupName, $ec2, $port = null) {
            $securityGroups = $ec2->describeSecurityGroups([
                'Filters' => [
                    [
                        'Name' => 'vpc-id',
                        'Values' => [env('AWS_VPC')]
                    ]
                ]
            ])['SecurityGroups'];

            $securityGroupExists = false;
    
            foreach ($securityGroups as $key => $securityGroup) {
                if ($securityGroup['GroupName'] == $securityGroupName) {
                    
                    $securityGroupExists = true;
                    $returnSecurityGroupId = $securityGroup['GroupId'];
                
                }
            }

            // Create the security group if it doesn't exist
            if ($securityGroupExists === false) {
    
                $returnSecurityGroupId = $ec2->createSecurityGroup([
                    'GroupName' => $securityGroupName,
                    'VpcId' => env('AWS_VPC'),
                    'Description' => 'Used by ' . $securityGroupName,
                ])['GroupId'];
                $ec2->authorizeSecurityGroupIngress([
                    'GroupId' => $returnSecurityGroupId,
                    'IpPermissions' => [
                        [
                            'IpProtocol' => '-1',
                            'UserIdGroupPairs' => [
                                [
                                    'Description' => 'Intra-security group access',
                                    'GroupId' => $returnSecurityGroupId,
                                ],
                            ],
                        ],
                    ],
                ]);
    
            }

            // If a port has been passed then we need to enable access to it from the ingress security group
            if ($port !== null) {
                try { 
                    $ec2->authorizeSecurityGroupIngress([
                        'GroupId' => $returnSecurityGroupId,
                        'IpPermissions' => [
                            [
                                'IpProtocol' => 'tcp',
                                'ToPort' => $port,
                                'FromPort' => $port,
                                'UserIdGroupPairs' => [
                                    [
                                        'Description' => 'Service Ingress Access',
                                        'GroupId' => env('AWS_INGRESS_SECURITY_GROUP_ID'),
                                    ],
                                ],
                            ],
                        ],
                    ]);
                }
                catch (AwsException $e) {
                    //return ''; 
                }
            }

            return $returnSecurityGroupId;

        }

        $namespaceSecurityGroup = ensureSecurityGroup($namespace . '-' . $environment, $ec2);    

        // Security group now exists

        // IAM role and instance profile
        $awsOptions = [
            'region'            => 'eu-west-1',
            'version'           => '2010-05-08',
        ];

        $iam = new IamClient($awsOptions);

        // List the roles on the account
        $roles = $iam->ListRoles()['Roles'];

        $roleExists = false;

        foreach ($roles as $key => $role) {
            if ($role['RoleName'] == $namespace . '-' . $environment) {
                $roleExists = true;
                $this->info('IAM role ' . $namespace . '-' . $environment . ' already exists');
                $namespaceRoleArn = $role['Arn'];
            }
        }

        // Create the role and instance profile

        if ($roleExists === false) {
            $this->info('Creating IAM role ' . $namespace . '-' . $environment);
            $namespaceRoleArn = $iam->createRole([
                'AssumeRolePolicyDocument' => '{"Version": "2012-10-17","Statement": {"Effect": "Allow","Principal": {"Service": "ec2.amazonaws.com"},"Action": "sts:AssumeRole"}}',
                'Description' => 'EC2 role for ' . $namespace . '-' . $environment,
                'RoleName' => $namespace . '-' . $environment,
            ])['Role']['Arn'];
        }

        $this->info('Namespace IAM role is ' . $namespaceRoleArn);

$rolePolicyDocument = '{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "VisualEditor0",
            "Effect": "Allow",
            "Action": [
                "ecr:GetLifecyclePolicyPreview",
                "ecr:GetDownloadUrlForLayer",
                "ecr:BatchGetImage",
                "ecr:DescribeImages",
                "ecr:ListTagsForResource",
                "ecr:BatchCheckLayerAvailability",
                "ecr:GetLifecyclePolicy",
                "ecr:GetRepositoryPolicy"
            ],
            "Resource": "arn:aws:ecr:' . env('AWS_REGION') . ':' . env('AWS_ACCOUNT') . ':repository/' . $namespace . '"
        },
        {
            "Sid": "VisualEditor1",
            "Effect": "Allow",
            "Action": "ecr:GetAuthorizationToken",
            "Resource": "*"
        }
    ]
}';

        // Check the policies and ensure that a namespace policy exists
        $policies = $iam->listPolicies([])['Policies'];

        $policyExists = false;

        foreach ($policies as $key => $policy) {
            if ($policy['PolicyName'] == $namespace . '-' . $environment . '-namespace') {
                $policyExists = true;
                $this->info('Policy ' . $namespace . '-' . $environment . '-namespace' . ' already exists');
                $namespacePolicyArn = $policy['Arn'];
            }
        }

        if ($policyExists == false) {
            $namespacePolicyArn = $iam->createPolicy([
                'Description' => 'Namespace policy for ' . $namespace . '-' . $environment,
                'PolicyDocument' => $rolePolicyDocument,
                'PolicyName' => $namespace . '-' . $environment . '-namespace'
            ])['Policy']['Arn'];
            // Attach the policy to the role created above
            $iam->attachRolePolicy([
                'PolicyArn' => $namespacePolicyArn,
                'RoleName' => $namespace . '-' . $environment
            ]);
            $this->info('Attached namespace IAM policy to role');
        }

        $this->info('Namespace IAM policy is ' . $namespacePolicyArn);

        // Make sure that the namespace instance profile exists
        $instanceProfiles = $iam->ListInstanceProfiles([])['InstanceProfiles'];

        $instanceProfileExists = false;

        foreach ($instanceProfiles as $key => $instanceProfile) {
            if ($instanceProfile['InstanceProfileName'] == $namespace . '-' . $environment . '-namespace') {
                $instanceProfileExists = true;
                $this->info('Instance Profile ' . $namespace . '-' . $environment . '-namespace' . ' already exists');
                $namespaceInstanceProfileArn = $instanceProfile['Arn'];
            }
        }

        if ($instanceProfileExists == false) {
            $namespaceInstanceProfileArn = $iam->createInstanceProfile([
                'InstanceProfileName' => $namespace . '-' . $environment . '-namespace',
            ])['InstanceProfile']['Arn'];

            // Attach the role to the instance profile
            $iam->addRoleToInstanceProfile([
                'InstanceProfileName' => $namespace . '-' . $environment . '-namespace',
                'RoleName' => $namespace . '-' . $environment
            ]);
            $this->info('Attached role to instance profile');
        }

        // The user data to launch instances with:
        $userData = '#!/bin/bash

DOCKERIMAGE={{DOCKERIMAGE}}
PORT={{DOCKERPORT}}

# Update
yum update && yum -y upgrade

# Install Docker
yum install -y docker

# Install supervisor
easy_install supervisor

# Skeleton supervisor config
echo_supervisord_conf > /etc/supervisord.conf

# Create startup command
echo "
[program:container]
command=docker run --name Kontainer --expose $PORT -p $PORT:$PORT $DOCKERIMAGE
stdout_logfile=/tmp/container-stdout.log
stdout_logfile_maxbytes=1000000
stderr_logfile=/tmp/container-stderr.log
stderr_logfile_maxbytes=1000000" >> /etc/supervisord.conf

# Start Docker service
service docker start

# Login to ECR
$(aws ecr get-login --region eu-west-1 --no-include-email)

# Download container
docker pull $DOCKERIMAGE

# Run supervisor
supervisord -n -c /etc/supervisord.conf';

// End user data

        foreach ($dockerFiles as $key => $dockerFile) {

            $dockerPort = '80';

            // Create a security group for this dockerFile
            $dockerFileSecurityGroup = ensureSecurityGroup($dockerFile . '-' . $namespace . '-' . $environment, $ec2, $dockerPort);

            // Replace variables in the user data
            $thisUserData = str_replace('{{DOCKERIMAGE}}', $repositoryInfo['repositoryUri'] . ':' . $dockerFile . '-' . $build, $userData);
            $thisUserData = str_replace('{{DOCKERPORT}}', $dockerPort, $thisUserData);

            // Create launch templates
            $launchTemplate = $ec2->createLaunchTemplate([
                'LaunchTemplateName' => $dockerFile . '-' . $namespace . '-' . $branch . '-' . $environment . '-' . $build,
                'LaunchTemplateData' => [
                    'DisableApiTermination' => false,
                    'EbsOptimized' => true,
                    'ImageId' => env('AWS_AMI'),
                    'CpuCredits' => 'unlimited',
                    'InstanceInitiatedShutdownBehavior' => 'terminate',
                    'InstanceType' => 't3.nano',
                    'KeyName' => env('AWS_SSH_KEY'),
                    'SecurityGroupIds' => [$namespaceSecurityGroup, $dockerFileSecurityGroup],
                    'UserData' => base64_encode($thisUserData),
                    'IamInstanceProfile' => [
                        'Arn' => $namespaceInstanceProfileArn
                    ],
                ]
            ])['LaunchTemplate']['LaunchTemplateId'];

            $this->info('Created launch template ' . $dockerFile . '-' . $namespace . '-' . $branch . '-' . $environment . '-' . $build . ' with id ' . $launchTemplate);

            // Create autoscaling group
            $awsOptions = [
                'region'            => 'eu-west-1',
                'version'           => '2011-01-01',
            ];
    
            $autoscaling = new AutoScalingClient($awsOptions);

            $desiredCapacity = 1;
            $minCapacity = 1;
            $maxCapacity = 10;

            // Create autoscaling group:
            $autoscaling->createAutoScalingGroup([
                'AutoScalingGroupName' => $dockerFile . '-' . $namespace . '-' . $branch . '-' . $environment . '-' . $build,
                'DefaultCooldown' => 60,
                'DesiredCapacity' => $desiredCapacity,
                'HealthCheckGracePeriod' => 300,
                'HealthCheckType' => 'EC2',
                'LaunchTemplate' => [
                    'LaunchTemplateId' => $launchTemplate,
                    'Version' => '$Latest',
                ],
                'MaxSize' => $maxCapacity,
                'MinSize' => $minCapacity,
                'NewInstancesProtectedFromScaleIn' => false,
                'Tags' => [
                    [
                        'Key' => 'Name',
                        'PropagateAtLaunch' => true,
                        'Value' => $dockerFile . '-' . $namespace . '-' . $branch . '-' . $environment . '-' . $build,
                    ],
                    [
                        'Key' => 'namespace',
                        'PropagateAtLaunch' => true,
                        'Value' => $namespace,
                    ],
                ],
                //'TargetGroupARNs' => ['<string>', ...],
                'TerminationPolicies' => ['OldestInstance'],
                'VPCZoneIdentifier' => env('AWS_PRIVATE_AZ_A_SUBNET') . ',' . env('AWS_PRIVATE_AZ_B_SUBNET') . ',' . env('AWS_PRIVATE_AZ_C_SUBNET'),
            ]);

        }

            

    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
