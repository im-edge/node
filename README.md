IMEdge - Monitoring on the Edge
===============================

IMEdge ships a bunch of powerful components for your Open Source Monitoring
environment. As in Edge Computing best practices, this brings processing
closer to the data source.

Installation
------------

### CentOS 9

PHP on CentOS 9 is outdated, therefore we're going to install a recent PHP
version via REMI:

```shell
# Install the REMI repositoriy...
dnf -y install https://rpms.remirepo.net/enterprise/remi-release-9.rpm
# ...and disable it by default:
dnf config-manager --set-disabled remi-modular --set-disabled remi-safe
# Install related dependencies
dnf -y install rrdtool redis openssl-perl php83 php83-php-pecl-event \
  php83-php-pecl-ev php83-php-gmp php83-php-intl php83-php-ldap \
  php83-php-mysqlnd php83-php-mbstring php83-php-pdo php83-php-sodium \
  php83-php-xml php83-php-soap php83-php-phpiredis php83-php-process \
  php83-php-pecl-zip --enablerepo=remi-safe,remi-modular
# Add the REMI php binary path to the IMEdge node daemons path:
echo PATH="/opt/remi/php83/root/usr/bin:/opt/remi/php83/root/usr/sbin:$PATH" \
  > /etc/default/imedge
```

Please note that this is not touching your default PHP installation, if any.
The IMEdge Node package has no hard dependency on a specific PHP version, but
assumes a recent version (>= 8.1) being installed and available.

Now we're ready to install the latest IMEdge node package and a bunch of related
feature packages:

```shell
dnf -y install \
  https://github.com/im-edge/node/releases/download/v0.9.10/imedge-node-0.9.10-1.noarch.rpm \
  https://github.com/im-edge/inventory-feature/releases/download/v0.12.1/imedge-feature-inventory-0.12.1-1.noarch.rpm \
  https://github.com/im-edge/metrics-feature/releases/download/v0.17.0/imedge-feature-metrics-0.17.0-1.noarch.rpm \
  https://github.com/im-edge/snmp-feature/releases/download/v0.10.0/imedge-feature-snmp-0.10.0-1.noarch.rpm \
  https://github.com/im-edge/tcp-feature/releases/download/v0.6.0/imedge-feature-tcp-0.6.0-1.noarch.rpm
```

Your IMEdge Node is now ready to go, and can be started:

```shell
systemctl enable --now imedge
```

You can verify correct operation via `systemctl status imedge.service`:

![IMEdge Node daemon status](doc/screenshot/00_preview/0001_systemctl-status.png)
