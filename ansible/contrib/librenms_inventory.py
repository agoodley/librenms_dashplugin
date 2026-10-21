#!/usr/bin/env python3
"""Ansible dynamic inventory from LibreNMS, for the LibreNMS Ansible plugin.

Copy this file into the playbook repository, make it executable (git update-index --chmod=+x) and point a
Semaphore UI inventory of type "file" at it. It asks the plugin for the inventory of the devices the token's
user may see; the plugin builds it (hosts, groups, ansible_network_os, cleaned librenms_* facts).

Environment (in Semaphore UI: an environment with the variable and the secret, used by the templates):
  LIBRENMS_URL        LibreNMS' address, e.g. https://librenms.example.com
  LIBRENMS_TOKEN      a LibreNMS API token (keep it a secret)
  LIBRENMS_CA_BUNDLE  optional: a PEM file of CA certificates to verify LibreNMS' certificate with
  LIBRENMS_TIMEOUT    optional: seconds to wait for LibreNMS (default 30)

Usage: librenms_inventory.py --list | --host NAME

Python standard library only. TLS certificates are always verified; redirects are not followed (the token would
go with them). Errors go to stderr with a non-zero exit code, so Ansible reports them.
"""
import argparse
import json
import os
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request

ENDPOINT = '/plugin/ansible/api/inventory'
VERSION = '1'


class InventoryError(Exception):
    """What went wrong, in words that say what to check."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Refuse redirects: urllib would send the X-Auth-Token header to wherever they point."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise InventoryError(f'LibreNMS answered with a redirect (HTTP {code}) to {newurl}: set LIBRENMS_URL to the address it redirects to')


def settings(environ):
    """LIBRENMS_URL, LIBRENMS_TOKEN, LIBRENMS_CA_BUNDLE and LIBRENMS_TIMEOUT, checked."""
    url = environ.get('LIBRENMS_URL', '').strip().rstrip('/')
    token = environ.get('LIBRENMS_TOKEN', '').strip()
    ca_bundle = environ.get('LIBRENMS_CA_BUNDLE', '').strip() or None
    if not url:
        raise InventoryError('LIBRENMS_URL is not set: give the address of LibreNMS, e.g. https://librenms.example.com')
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in ('http', 'https') or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise InventoryError('LIBRENMS_URL must be an http(s) address without a user, ? or #, e.g. https://librenms.example.com')
    if not token:
        raise InventoryError('LIBRENMS_TOKEN is not set: create an API token in LibreNMS and give it to the runner as a secret')
    if ca_bundle and not os.path.isfile(ca_bundle):
        raise InventoryError(f'LIBRENMS_CA_BUNDLE {ca_bundle} is not a file')
    try:
        timeout = float(environ.get('LIBRENMS_TIMEOUT', '') or 30)
    except ValueError:
        raise InventoryError('LIBRENMS_TIMEOUT must be a number of seconds') from None
    return url, token, ca_bundle, timeout


def fetch(url, token, ca_bundle=None, timeout=30.0, host=None):
    """The plugin's inventory (or one host's variables) as a dict."""
    address = url + ENDPOINT + ('?' + urllib.parse.urlencode({'host': host}) if host else '')
    request = urllib.request.Request(address, headers={
        'X-Auth-Token': token, 'Accept': 'application/json', 'User-Agent': 'librenms-ansible-inventory/' + VERSION})
    context = ssl.create_default_context(cafile=ca_bundle)
    opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=context))
    try:
        with opener.open(request, timeout=timeout) as response:
            content_type = response.headers.get('Content-Type', '')
            body = response.read()
    except urllib.error.HTTPError as e:
        raise InventoryError(http_error(e.code, url)) from None
    except urllib.error.URLError as e:
        reason = e.reason
        if isinstance(reason, ssl.SSLError):
            raise InventoryError(f'cannot verify the TLS certificate of {url}: {reason}; '
                                 'give the CA that signed it in LIBRENMS_CA_BUNDLE') from None
        raise InventoryError(f'cannot reach LibreNMS at {url}: {reason}') from None
    except (OSError, ValueError) as e:
        raise InventoryError(f'cannot reach LibreNMS at {url}: {e}') from None
    if 'json' not in content_type:
        raise InventoryError(f'LibreNMS answered {content_type or "something"} instead of JSON: is LIBRENMS_URL the address of LibreNMS?')
    try:
        data = json.loads(body.decode('utf-8'))
    except (UnicodeDecodeError, ValueError):
        raise InventoryError('LibreNMS answered something that is not JSON') from None
    if not isinstance(data, dict):
        raise InventoryError('LibreNMS answered JSON that is not an inventory')
    return data


def http_error(code, url):
    """What an HTTP error means here."""
    if code == 401:
        return 'LibreNMS refused the API token (HTTP 401): check LIBRENMS_TOKEN (it may have been deleted or have expired)'
    if code == 403:
        return 'LibreNMS refused the request (HTTP 403): the token\'s user may not use this'
    if code == 404:
        return (f'no inventory at {url}{ENDPOINT} (HTTP 404): is the Ansible plugin installed and enabled in LibreNMS, '
                'and is LIBRENMS_URL right?')
    return f'LibreNMS answered HTTP {code}: see its logs/librenms.log'


def main(argv=None, environ=None, out=sys.stdout, err=sys.stderr):
    """Print the inventory (--list) or a host's variables (--host NAME); 0 on success, 1 on an error."""
    parser = argparse.ArgumentParser(description='Ansible dynamic inventory from LibreNMS (LibreNMS Ansible plugin)')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--list', action='store_true', help='the whole inventory')
    group.add_argument('--host', metavar='NAME', help='the variables of one host')
    args = parser.parse_args(argv)
    try:
        url, token, ca_bundle, timeout = settings(os.environ if environ is None else environ)
        data = fetch(url, token, ca_bundle, timeout, args.host)
    except InventoryError as e:
        print(f'librenms_inventory: {e}', file=err)
        return 1
    json.dump(data, out)
    out.write('\n')
    return 0


if __name__ == '__main__':
    sys.exit(main())
