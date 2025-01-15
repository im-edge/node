%define revision 1
%define git_version %( git describe | cut -c2- | tr -s '-' '+')
%define short_version %( git describe --long | cut -c2- | cut -d '-' -f 1 )
%define git_hash %( git rev-parse --short HEAD )
%define daemon_user imedge
%define daemon_group imedge
%define daemon_home /var/lib/%{daemon_user}
%define basedir         %{_datadir}/%{name}
%define bindir          %{_bindir}
%undefine __brp_mangle_shebangs
%define socket_path /run/imedge

Name:           imedge-node
Version:        %{git_version}
Release:        %{revision}%{?dist}
Summary:        IMEdge Node
Group:          Applications/System
License:        MIT
URL:            https://github.com/im-edge
Source0:        https://github.com/im-edge/node/archive/%{git_hash}.tar.gz
BuildArch:      noarch
BuildRoot:      %{_tmppath}/%{name}-%{git_version}-%{release}
Packager:       Thomas Gelf <thomas@gelf.net>

%description
IMEdge Node - Daemon

%prep

%build

%install
rm -rf %{buildroot}
mkdir -p %{buildroot}
mkdir -p %{buildroot}%{bindir}
mkdir -p %{buildroot}%{basedir}
mkdir -p %{buildroot}/lib/systemd/system
mkdir -p %{buildroot}/lib/tmpfiles.d
pwd
cd - # ???
pwd
cp -p bin/imedge %{buildroot}%{bindir}/
cp -p bin/imedge-worker %{buildroot}%{bindir}/
cp -pr contrib/systemd/imedge.service %{buildroot}/lib/systemd/system/
cp -pr src vendor %{buildroot}%{basedir}/
echo "d %{socket_path} 0755 %{daemon_user} %{daemon_group} -" > %{buildroot}/lib/tmpfiles.d/imedge.conf

%pre
getent group "%{daemon_group}" > /dev/null || groupadd -r "%{daemon_group}"
getent passwd "%{daemon_user}" > /dev/null || useradd -r -g "%{daemon_group}" \
-d "%{daemon_home}" -s "/sbin/nologin" "%{daemon_user}"
install -d -o "%{daemon_user}" -g "%{daemon_group}" -m 0750 "%{daemon_home}"

%post
systemd-tmpfiles --create /lib/tmpfiles.d/imedge.conf
systemctl daemon-reload

%clean
rm -rf %{buildroot}

%files
%defattr(-,root,root)
%{basedir}/src
%{basedir}/vendor
%{bindir}/imedge
%{bindir}/imedge-worker
/lib/systemd/system/imedge.service
/lib/tmpfiles.d/imedge.conf

%changelog
* Mon Jan 13 2025 Thomas Gelf <thomas@gelf.net> 0.9.4
- Initial packaging
