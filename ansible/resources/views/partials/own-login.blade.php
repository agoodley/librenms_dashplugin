{{-- A device's own-login marker (Inventory\OwnLogins; nothing when it logs in with the default login): $login, and
     $hostName, its inventory name, when it is a host. Never a login: the plugin sees none. --}}
@if($login)
    @php
        $ownLogin = $login['state'] === \Agoodley\LibrenmsAnsible\Inventory\OwnLogins::OWN;
        $ownLoginTitle = $ownLogin
            ? 'It logs in with a login of its own (install/rocky/device-logins.sh), not the default one: Ansible with the one of inventory/host_vars/'
                . ($hostName ?? '<its inventory name>') . '.yml in the playbook repository, Oxidized with the one of its group (' . $login['text'] . '). The plugin never sees a login.'
            : $login['text'] . '.';
    @endphp
    <span class="label label-{{ $ownLogin ? 'info' : 'warning' }} ans-own-login" data-state="{{ $login['state'] }}" title="{{ $ownLoginTitle }}">{{ $ownLogin ? 'own login' : ($login['state'] === \Agoodley\LibrenmsAnsible\Inventory\OwnLogins::OTHER ? 'another device\'s login' : 'own login not applied') }}</span>
@endif
