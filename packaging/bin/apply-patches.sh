#!/bin/sh

patch -p1 -s -r - --forward --no-backup-if-mismatch -d vendor/amphp/socket < packaging/patches/01_amphp-socket_112.patch
