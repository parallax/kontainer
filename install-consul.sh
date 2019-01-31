#!/bin/bash
yum update && yum upgrade -y
wget https://releases.hashicorp.com/consul/1.4.2/consul_1.4.2_linux_amd64.zip
unzip consul_1.4.2_linux_amd64.zip 
rm -f consul_1.4.2_linux_amd64.zip
chmod +x consul
mv consul /usr/local/bin/
useradd consul
mkdir -p /opt/consul
mkdir -p /var/log/consul/
chown consul:consul /opt/consul
chown consul:consul /var/log/consul/
easy_install supervisor
echo_supervisord_conf > /etc/supervisord.conf
# Create startup command
echo "
[program:consul]
command=/usr/local/bin/consul agent -client=`curl --silent http://169.254.169.254/latest/meta-data/local-ipv4` -data-dir=/opt/consul -server -retry-join 'provider=aws tag_key=Consul tag_value=True'
user=consul
stdout_logfile=/var/log/consul/stdout.log
stdout_logfile_maxbytes=1000000
stderr_logfile=/var/log/consul/stderr.log
stderr_logfile_maxbytes=1000000" >> /etc/supervisord.conf

supervisord -n -c /etc/supervisord.conf