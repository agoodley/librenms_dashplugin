"""Tests of contrib/librenms_inventory.py against a local HTTP server (standard library only).

Run: python3 -m unittest discover -s ansible/contrib/tests
"""
import http.server
import io
import json
import os
import sys
import threading
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
import librenms_inventory  # noqa: E402

TOKEN = '7|test-token-0123456789'
FORBIDDEN = ("This API token's user is not the inventory user chosen in the Ansible plugin settings: "
             "the runner must fetch the inventory with that user's token (an admin can change the inventory user there).")
INVENTORY = {'_meta': {'hostvars': {'sw1': {'ansible_host': '10.0.0.1', 'ansible_network_os': 'cisco.ios.ios'}}},
             'all': {'children': ['os_ios']}, 'os_ios': {'hosts': ['sw1']}}


class Handler(http.server.BaseHTTPRequestHandler):
    """The plugin's endpoint, and a few broken ones."""

    def log_message(self, *args):
        pass

    def do_GET(self):
        if self.path.startswith('/redirect'):
            self.send_response(302)
            self.send_header('Location', 'http://evil.example/steal')
            self.end_headers()
            return
        if self.path.startswith('/html'):
            self.reply(200, b'<html>login</html>', 'text/html')
            return
        if self.path.startswith('/other-user'):
            self.reply(403, json.dumps({'error': FORBIDDEN}).encode())
            return
        if self.path.startswith('/noisy'):
            self.reply(403, json.dumps({'error': '\x1b[31mRefused\x1b[0m\r\nfor ' + TOKEN + ' and ' + TOKEN.partition('|')[2]
                                        + ' \u202eevil\x00 ' + 'x' * 400}).encode())
            return
        if self.path.startswith('/forbidden-html'):
            self.reply(403, b'<html>Forbidden</html>', 'text/html')
            return
        if self.path.startswith('/read-only'):
            self.reply(405, b'{"error": "The inventory endpoint is read only: fetch it with GET."}')
            return
        if self.path.startswith('/broken'):
            self.reply(500, b'{"error": "The inventory failed (SQLSTATE[42S02] details)"}')
            return
        if not self.path.startswith('/plugin/ansible/api/inventory'):
            self.reply(404, b'{}')
            return
        if self.headers.get('X-Auth-Token') != TOKEN:
            self.reply(401, b'{"message":"Unauthenticated."}')
            return
        if 'host=sw1' in self.path:
            self.reply(200, json.dumps(INVENTORY['_meta']['hostvars']['sw1']).encode())
            return
        self.reply(200, json.dumps(INVENTORY).encode())

    def reply(self, status, body, content_type='application/json'):
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)


class InventoryScriptTest(unittest.TestCase):

    @classmethod
    def setUpClass(cls):
        cls.server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
        cls.url = 'http://127.0.0.1:%d' % cls.server.server_port
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()

    def run_script(self, argv, environ):
        out, err = io.StringIO(), io.StringIO()
        code = librenms_inventory.main(argv, environ, out, err)
        return code, out.getvalue(), err.getvalue()

    def test_list(self):
        code, out, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(0, code, err)
        self.assertEqual(INVENTORY, json.loads(out))

    def test_host(self):
        code, out, _ = self.run_script(['--host', 'sw1'], {'LIBRENMS_URL': self.url, 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(0, code)
        self.assertEqual('10.0.0.1', json.loads(out)['ansible_host'])

    def test_bad_token(self):
        code, out, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url, 'LIBRENMS_TOKEN': 'wrong'})
        self.assertEqual(1, code)
        self.assertEqual('', out)
        self.assertIn('refused the API token (HTTP 401)', err)
        self.assertNotIn('wrong', err)

    def test_refusal_quotes_the_reason(self):
        code, out, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/other-user', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        self.assertEqual('', out)
        self.assertEqual('librenms_inventory: LibreNMS refused the request (HTTP 403): ' + FORBIDDEN + '\n', err)
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/read-only', 'LIBRENMS_TOKEN': TOKEN})
        self.assertIn('LibreNMS refused the request (HTTP 405): The inventory endpoint is read only: fetch it with GET.', err)

    def test_refusal_without_a_reason(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/forbidden-html', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        self.assertIn("LibreNMS refused the request (HTTP 403): the token's user may not use this", err)

    def test_quoted_reason_is_cleaned(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/noisy', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        line = err.rstrip('\n')
        self.assertNotIn('\n', line)
        self.assertTrue(line.isprintable(), repr(line))
        self.assertNotIn('test-token-0123456789', line)
        self.assertIn('HTTP 403): Refused for [the token] and [the token] evil x', line)
        self.assertTrue(line.endswith('...'))
        self.assertLessEqual(len(line), len('librenms_inventory: LibreNMS refused the request (HTTP 403): ') + librenms_inventory.DETAIL_MAX)

    def test_server_errors_are_not_quoted(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/broken', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        self.assertIn('LibreNMS answered HTTP 500: see its logs/librenms.log', err)
        self.assertNotIn('SQLSTATE', err)

    def test_missing_settings(self):
        self.assertIn('LIBRENMS_URL is not set', self.run_script(['--list'], {})[2])
        self.assertIn('LIBRENMS_TOKEN is not set', self.run_script(['--list'], {'LIBRENMS_URL': self.url})[2])
        self.assertIn('must be an http(s) address', self.run_script(['--list'], {'LIBRENMS_URL': 'ftp://x', 'LIBRENMS_TOKEN': TOKEN})[2])
        self.assertIn('without a user', self.run_script(['--list'], {'LIBRENMS_URL': 'https://u:p@x', 'LIBRENMS_TOKEN': TOKEN})[2])
        self.assertIn('is not a file', self.run_script(['--list'], {'LIBRENMS_URL': self.url, 'LIBRENMS_TOKEN': TOKEN,
                                                                    'LIBRENMS_CA_BUNDLE': '/nonexistent.pem'})[2])
        self.assertIn('number of seconds', self.run_script(['--list'], {'LIBRENMS_URL': self.url, 'LIBRENMS_TOKEN': TOKEN,
                                                                        'LIBRENMS_TIMEOUT': 'soon'})[2])

    def test_redirect_is_refused(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/redirect', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        self.assertIn('redirect (HTTP 302)', err)

    def test_not_json(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/html', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        self.assertIn('instead of JSON', err)

    def test_wrong_path(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': self.url + '/nothing', 'LIBRENMS_TOKEN': TOKEN})
        self.assertEqual(1, code)
        self.assertIn('HTTP 404', err)

    def test_unreachable(self):
        code, _, err = self.run_script(['--list'], {'LIBRENMS_URL': 'http://127.0.0.1:9', 'LIBRENMS_TOKEN': TOKEN, 'LIBRENMS_TIMEOUT': '2'})
        self.assertEqual(1, code)
        self.assertIn('cannot reach LibreNMS at http://127.0.0.1:9', err)

    def test_tls_is_verified(self):
        context = librenms_inventory.ssl.create_default_context()
        self.assertEqual(librenms_inventory.ssl.CERT_REQUIRED, context.verify_mode)
        self.assertTrue(context.check_hostname)


if __name__ == '__main__':
    unittest.main()
