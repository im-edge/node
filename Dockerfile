FROM debian:bookworm-slim

LABEL org.opencontainers.image.source=https://github.com/im-edge/node
LABEL org.opencontainers.image.description="IMEdge Node"
LABEL org.opencontainers.image.licenses=MIT

RUN echo 'debconf debconf/frontend select Noninteractive' | debconf-set-selections && apt-get update && \
    apt-get -y install php-cli php-curl php-mysql php-redis php-mbstring php-gmp php-intl php-json php-zip && \
    apt-get -y install php-ctype php-iconv php-posix php-simplexml php-sockets && \
    apt-get -y install redis-server rrdtool rrdcached && \
    groupadd -r imedge && \
    useradd -r -g imedge -d /var/lib/imedge -s /sbin/nologin imedge && \
    install -d -o imedge -g imedge -m 0750 /var/lib/imedge && \
    install -d -o imedge -g imedge -m 0755 /run/imedge

COPY src /usr/share/imedge-node/src
COPY vendor /usr/share/imedge-node/vendor
COPY bin/imedge /usr/bin/
COPY bin/imedge-worker /usr/bin/

VOLUME /var/lib/imedge

ENTRYPOINT ["/usr/bin/imedge", "daemon"]
