"""install/rocky/lib/semaphore_api.py: the Semaphore UI side of install.sh, through Semaphore's API.

    python3 -I semaphore_api.py setup < request.json      the project for LibreNMS, as the Ansible plugin's README
                                                           sets it up (see install.sh's part semaphore)
    python3 -I semaphore_api.py password < request.json   set a user's password (the admin's: never on a command line)
    python3 -I semaphore_api.py token-delete < request.json

The request is a JSON object on stdin (the API token and the secrets never go on a command line); the answer is a
JSON object on stdout ({"error": ...} and exit code 1 when something fails). Each object is found by its name, made
when it is missing, and repaired only where the plugin needs it to be as the README says (the rest stays as an admin
set it): a second run changes nothing that is right.
"""
import json
import sys
import urllib.error
import urllib.request


class ApiError(Exception):
    pass


class Api:
    def __init__(self, url, token):
        self.url = url.rstrip('/') + '/api'
        self.token = token
        # the proxies of the environment never apply: Semaphore UI runs on this server
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

    def call(self, method, path, body=None, ok=(200, 201, 204)):
        data = None if body is None else json.dumps(body).encode()
        request = urllib.request.Request(self.url + path, data=data, method=method, headers={
            'Authorization': 'Bearer ' + self.token, 'Content-Type': 'application/json', 'Accept': 'application/json'})
        try:
            with self.opener.open(request, timeout=30) as response:
                raw = response.read().decode()
                status = response.status
        except urllib.error.HTTPError as e:
            raw = e.read().decode(errors='replace')
            status = e.code
        except OSError as e:
            raise ApiError('%s %s: %s' % (method, path, e))
        if status not in ok:
            raise ApiError('%s %s answered HTTP %d: %s' % (method, path, status, raw[:300]))
        return json.loads(raw) if raw.strip() else None


changes = []


def by_name(items, name, key='name'):
    for item in items or []:
        if item.get(key) == name:
            return item
    return None


def ensure_project(api, name):
    project = by_name(api.call('GET', '/projects'), name)
    if project is None:
        project = api.call('POST', '/projects', {'name': name, 'alert': False})
        changes.append('project "%s" (#%d)' % (name, project['id']))
    return project['id']


def ensure_key(api, p, spec):
    """A Login with password key; its secret is set when it is made, and again when the request says so."""
    keys = api.call('GET', '/project/%d/keys' % p)
    key = by_name(keys, spec['name'])
    secret = {'login': spec.get('login', ''), 'password': spec.get('password', '')}
    if key is None:
        key = api.call('POST', '/project/%d/keys' % p, {'name': spec['name'], 'type': 'login_password', 'project_id': p,
                                                       'login_password': secret})
        changes.append('key store: "%s"%s' % (spec['name'], '' if secret['password'] else ' (empty: fill it in)'))
        return key['id'], not secret['password']
    if key.get('type') != 'login_password':
        raise ApiError('the key "%s" of the project is not a Login with password key: rename or remove it' % spec['name'])
    empty = bool(key.get('empty'))
    if spec.get('update') or (empty and secret['password']):
        api.call('PUT', '/project/%d/keys/%d' % (p, key['id']), {'id': key['id'], 'name': spec['name'], 'type': 'login_password',
                                                                  'project_id': p, 'override_secret': True, 'login_password': secret})
        changes.append('key store: "%s" set' % spec['name'])
        empty = not secret['password']
    return key['id'], empty


def none_key(api, p):
    key = next((k for k in api.call('GET', '/project/%d/keys' % p) if k.get('type') == 'none'), None)
    if key is None:
        key = api.call('POST', '/project/%d/keys' % p, {'name': 'None', 'type': 'none', 'project_id': p})
    return key['id']


def ensure_repository(api, p, spec, key_id):
    repos = api.call('GET', '/project/%d/repositories' % p)
    repo = by_name(repos, spec['name'])
    want = {'git_url': spec['git_url'], 'git_branch': spec['git_branch']}
    if repo is None:
        repo = api.call('POST', '/project/%d/repositories' % p, dict(want, name=spec['name'], project_id=p, ssh_key_id=key_id))
        changes.append('repository "%s" (%s)' % (spec['name'], spec['git_url']))
    elif any(repo.get(k) != v for k, v in want.items()):
        api.call('PUT', '/project/%d/repositories/%d' % (p, repo['id']), dict(repo, **want))
        changes.append('repository "%s" points at %s again' % (spec['name'], spec['git_url']))
    return repo['id']


def ensure_inventory(api, p, spec, repo_id, login_key, become_key):
    inventories = api.call('GET', '/project/%d/inventory' % p)
    inv = by_name(inventories, spec['name'])
    want = {'type': 'file', 'inventory': spec['path'], 'repository_id': repo_id}
    if inv is None:
        inv = api.call('POST', '/project/%d/inventory' % p, dict(want, name=spec['name'], project_id=p, ssh_key_id=login_key,
                                                                 become_key_id=become_key))
        changes.append('inventory "%s" (the plugin\'s inventory script)' % spec['name'])
    elif any(inv.get(k) != v for k, v in want.items()):
        api.call('PUT', '/project/%d/inventory/%d' % (p, inv['id']), dict(inv, **want))
        changes.append('inventory "%s" runs the plugin\'s inventory script again' % spec['name'])
    return inv['id']


def ensure_environment(api, p, spec):
    envs = api.call('GET', '/project/%d/environment' % p)
    env = by_name(envs, spec['name'])
    secrets = [{'type': 'env', 'name': n, 'secret': v, 'operation': 'create'} for n, v in spec['secret_env'].items()]
    if env is None:
        env = api.call('POST', '/project/%d/environment' % p, {'name': spec['name'], 'project_id': p, 'json': '{}',
                                                               'env': json.dumps(spec['env']), 'secrets': secrets})
        changes.append('variable group "%s" (%s, the secret %s)' % (spec['name'], ', '.join('%s=%s' % kv for kv in spec['env'].items()),
                                                                     ', '.join(spec['secret_env'])))
        return env['id']
    env = api.call('GET', '/project/%d/environment/%d' % (p, env['id']))
    current = json.loads(env.get('env') or '{}')
    variables = dict(current, **spec['env'])
    updates = []
    known = {s['name']: s for s in env.get('secrets') or [] if s.get('type') == 'env'}
    for name, value in spec['secret_env'].items():
        if name not in known:
            updates.append({'type': 'env', 'name': name, 'secret': value, 'operation': 'create'})
        elif spec.get('update_secrets'):
            updates.append({'id': known[name]['id'], 'type': 'env', 'name': name, 'secret': value, 'operation': 'update'})
    if variables != current or updates:
        body = {'id': env['id'], 'name': env['name'], 'project_id': p, 'json': env.get('json') or '{}', 'env': json.dumps(variables),
                'secrets': updates}
        if 'password' in env:
            body['password'] = env['password']
        api.call('PUT', '/project/%d/environment/%d' % (p, env['id']), body)
        what = ['%s=%s' % (k, v) for k, v in spec['env'].items() if current.get(k) != v] + ['the secret ' + u['name'] for u in updates]
        changes.append('variable group "%s": %s' % (spec['name'], ', '.join(what)))
    return env['id']


def ensure_template(api, p, spec, inv_id, repo_id, env_id):
    templates = api.call('GET', '/project/%d/templates' % p)
    t = by_name(templates, spec['name'])
    survey = {'name': spec['survey'], 'title': spec['survey_title'], 'description': spec['survey_description'], 'type': 'text', 'required': True}
    if t is None:
        body = {'project_id': p, 'name': spec['name'], 'description': spec['description'], 'playbook': spec['playbook'], 'app': 'ansible',
                'type': '', 'inventory_id': inv_id, 'repository_id': repo_id, 'environment_id': env_id,
                'arguments': json.dumps(spec['arguments']) if spec['arguments'] else None,
                'task_params': {'allow_override_limit': True}, 'survey_vars': [survey]}
        t = api.call('POST', '/project/%d/templates' % p, body)
        changes.append('template "%s" (#%d, %s)' % (spec['name'], t['id'], spec['playbook']))
        return t['id']
    # what the plugin needs of it (its README, Semaphore UI setup, steps 9 to 11)
    fixed = []
    t = dict(t)
    params = dict(t.get('task_params') or {})
    if not params.get('allow_override_limit'):
        params['allow_override_limit'] = True
        fixed.append('Ansible prompts: Limit')
    if params.get('limit') or t.get('limit'):
        params.pop('limit', None)
        t.pop('limit', None)
        fixed.append('no limit of its own')
    t['task_params'] = params
    for field, value, label in (('inventory_id', inv_id, 'the LibreNMS inventory'), ('repository_id', repo_id, 'the playbook repository'),
                                ('environment_id', env_id, 'the LibreNMS variable group'), ('playbook', spec['playbook'], 'its playbook')):
        if t.get(field) != value:
            t[field] = value
            fixed.append(label)
    if env_id not in (t.get('environment_ids') or []):
        t['environment_ids'] = [env_id] + [e for e in (t.get('environment_ids') or []) if e != env_id]
        if 'the LibreNMS variable group' not in fixed:
            fixed.append('the LibreNMS variable group')
    vars_ = list(t.get('survey_vars') or [])
    var = by_name(vars_, spec['survey'])
    if var is None or var.get('type') != 'text' or not var.get('required'):
        vars_ = [v for v in vars_ if v.get('name') != spec['survey']] + [dict(var or {}, **survey)]
        t['survey_vars'] = vars_
        fixed.append('the survey variable %s (Text, required)' % spec['survey'])
    if fixed:
        t.pop('tasks', None)
        t.pop('permissions', None)
        api.call('PUT', '/project/%d/templates/%d' % (p, t['id']), t)
        changes.append('template "%s": %s' % (spec['name'], ', '.join(fixed)))
    return t['id']


def ensure_runner(api, p, spec):
    users = api.call('GET', '/users')
    user = by_name(users, spec['username'], 'username')
    if user is None:
        user = api.call('POST', '/users', {'name': spec['name'], 'username': spec['username'], 'email': spec['email'],
                                           'password': spec['password'], 'admin': False, 'alert': False})
        changes.append('user %s (not an admin)' % spec['username'])
    elif user.get('admin'):
        raise ApiError('the Semaphore UI user %s is an admin: the Ansible plugin refuses an admin\'s token. Make it a plain user '
                       '(or remove it, and the installer makes it again)' % spec['username'])
    members = api.call('GET', '/project/%d/users' % p)
    member = next((m for m in members or [] if m.get('id') == user['id']), None)
    if member is None:
        api.call('POST', '/project/%d/users' % p, {'user_id': user['id'], 'role': 'task_runner'})
        changes.append('user %s: Task Runner of the project' % spec['username'])
    elif member.get('role') != 'task_runner':
        api.call('PUT', '/project/%d/users/%d' % (p, user['id']), {'role': 'task_runner'})
        changes.append('user %s: Task Runner of the project (was %s)' % (spec['username'], member.get('role')))
    return user['id']


def setup(req):
    api = Api(req['url'], req['token'])
    p = ensure_project(api, req['project'])
    keys = req['keys']
    login_key, login_empty = ensure_key(api, p, keys['login'])
    become_key, become_empty = ensure_key(api, p, keys['become'])
    repo_id = ensure_repository(api, p, req['repository'], none_key(api, p))
    inv_id = ensure_inventory(api, p, req['inventory'], repo_id, login_key, become_key)
    env_id = ensure_environment(api, p, req['environment'])
    template_ids = {}
    for spec in req['templates']:
        template_ids[spec['kind']] = ensure_template(api, p, spec, inv_id, repo_id, env_id)
    runner_id = ensure_runner(api, p, req['runner'])
    return {'project_id': p, 'inventory_id': inv_id, 'environment_id': env_id, 'repository_id': repo_id, 'templates': template_ids,
            'runner_user_id': runner_id, 'login_key_empty': login_empty, 'become_key_empty': become_empty, 'changes': changes}


def password(req):
    api = Api(req['url'], req['token'])
    user = by_name(api.call('GET', '/users'), req['username'], 'username')
    if user is None:
        raise ApiError('Semaphore UI has no user %s' % req['username'])
    body = {'password': req['password']}
    if req.get('current_password'):
        # a user who changes their own password gives the current one too
        body['current_password'] = req['current_password']
    api.call('POST', '/users/%d/password' % user['id'], body)
    return {'user_id': user['id']}


def token_delete(req):
    api = Api(req['url'], req['token'])
    api.call('DELETE', '/user/tokens/' + req['token'], ok=(200, 204, 404))
    return {}


def main():
    command = sys.argv[1] if len(sys.argv) > 1 else ''
    try:
        req = json.load(sys.stdin)
        handler = {'setup': setup, 'password': password, 'token-delete': token_delete}.get(command)
        if handler is None:
            raise ApiError('unknown command %r' % command)
        print(json.dumps(handler(req)))
    except (ApiError, KeyError, ValueError) as e:
        # stderr goes to the installer's log (stdout is not logged: it can hold tokens)
        print('semaphore_api.py %s: %s' % (command, e), file=sys.stderr)
        print(json.dumps({'error': str(e), 'changes': changes}))
        sys.exit(1)


if __name__ == '__main__':
    main()
