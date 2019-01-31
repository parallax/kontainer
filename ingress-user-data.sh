#!/bin/bash
yum update && yum upgrade -y
useradd traefik
easy_install supervisor
echo_supervisord_conf > /etc/supervisord.conf
wget https://github.com/containous/traefik/releases/download/v1.7.8/traefik_linux-amd64
chmod +x traefik_linux-amd64
mv traefik_linux-amd64 /usr/bin/traefik

mkdir -p /var/log/traefik/
chown traefik:traefik /var/log/traefik

# Bind low ports
setcap 'cap_net_bind_service=+ep' /usr/bin/traefik 

# Create startup command
echo "
[program:traefik]
command=/usr/bin/traefik --consul --consul.endpoint=http://internal-consul-1137894369.eu-west-1.elb.amazonaws.com:8500 --api.statistics
user=traefik
stdout_logfile=/var/log/traefik/stdout.log
stdout_logfile_maxbytes=1000000
stderr_logfile=/var/log/traefik/stderr.log
stderr_logfile_maxbytes=1000000" >> /etc/supervisord.conf

supervisord -n -c /etc/supervisord.conf