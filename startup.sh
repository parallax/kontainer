#!/bin/bash

DOCKERIMAGE=140358210270.dkr.ecr.eu-west-1.amazonaws.com/fargate:test

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
command=docker run --expose 80 -p 80:80 $DOCKERIMAGE
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
supervisord -n -c /etc/supervisord.conf