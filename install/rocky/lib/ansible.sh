# shellcheck shell=bash
# install/rocky/lib/ansible.sh: part 3, Ansible where Semaphore UI runs it: a Python 3.12 virtual environment in
# /opt/ansible with the ansible package of files/ansible-requirements.txt (ansible-core 2.20 and the network
# collections the Ansible plugin's README names, with ansible.netcommon and their Python libraries). Semaphore UI's
# service has /opt/ansible/bin first on its PATH. The run checks that this Ansible prints the check-mode markers
# (ANSIBLE_CHECK_MODE_MARKERS) the plugin proves its dry runs with.

ANSIBLE_VENV=/opt/ansible

part_ansible() {
    part_header ansible "Ansible (ansible-core 2.20 with the network collections) in $ANSIBLE_VENV"
    local python=python3.12 pkgs=(python3.12 python3.12-pip)
    if [[ $OS_MAJOR == 10 ]]; then python=python3; pkgs=(python3 python3-pip); fi
    dnf_install "Python 3.12 (ansible-core 2.20 needs it)" "${pkgs[@]}"
    local requirements=$FILES_DIR/ansible-requirements.txt want have=''
    want=$(sha256sum "$requirements" | cut -d' ' -f1)
    [[ -f $ANSIBLE_VENV/.installer-requirements ]] && have=$(<"$ANSIBLE_VENV/.installer-requirements")
    if [[ $have == "$want" && -x $ANSIBLE_VENV/bin/ansible-playbook ]]; then
        detail "$ANSIBLE_VENV has the Ansible of ansible-requirements.txt"
    else
        if [[ ! -x $ANSIBLE_VENV/bin/python ]]; then
            act "make the Python virtual environment $ANSIBLE_VENV" "$python" -m venv "$ANSIBLE_VENV"
        fi
        local args=(-r "$requirements")
        if [[ -n $PIP_WHEELS ]]; then args=(--no-index --find-links "$PIP_WHEELS" "${args[@]}"); fi
        FAIL_HINT="check that this server reaches PyPI, or give local wheels (--pip-wheels DIR)" \
            act "install Ansible into $ANSIBLE_VENV (ansible 13.5.0: ansible-core 2.20.10 and the collections)" \
            "$ANSIBLE_VENV/bin/pip" install --disable-pip-version-check --no-cache-dir "${args[@]}"
        if [[ $CHECK == 0 ]]; then printf '%s\n' "$want" >"$ANSIBLE_VENV/.installer-requirements"; fi
    fi
    [[ $CHECK == 1 && ! -x $ANSIBLE_VENV/bin/ansible-playbook ]] && { summary_add "ansible 13.5.0 (ansible-core 2.20.10): would be installed in $ANSIBLE_VENV"; return 0; }
    local version; version=$("$ANSIBLE_VENV/bin/ansible" --version 2>/dev/null | head -n 1 || true)
    ansible_markers_test
    summary_add "${version:-ansible} in $ANSIBLE_VENV, check-mode markers: ${ANSIBLE_MARKERS:-not checked}"
}

# ansible_markers_test: a dry run of a one-task playbook on localhost with ANSIBLE_CHECK_MODE_MARKERS=true must print
# the DRY RUN banner and [CHECK MODE] after the task's banner, as the plugin's dry run proof needs.
ansible_markers_test() {
    local d=$WORK/markers
    mkdir -p "$d/home"
    printf -- '- hosts: localhost\n  gather_facts: false\n  tasks:\n    - name: marker test\n      ansible.builtin.debug:\n        msg: ok\n' >"$d/play.yml"
    local out
    out=$(cd "$d" && HOME=$d/home ANSIBLE_HOME=$d/home ANSIBLE_LOCAL_TEMP=$d/tmp ANSIBLE_CHECK_MODE_MARKERS=true ANSIBLE_NOCOLOR=1 \
        "$ANSIBLE_VENV/bin/ansible-playbook" --check -i localhost, -c local "$d/play.yml" 2>&1 || true)
    printf '%s\n' "$out" >>"$LOG_FILE"
    if grep -q 'DRY RUN' <<<"$out" && grep -q 'TASK \[marker test\].*\[CHECK MODE\]' <<<"$out"; then
        ANSIBLE_MARKERS=ok
        detail "Ansible prints the check-mode markers (DRY RUN, [CHECK MODE])"
    else
        ANSIBLE_MARKERS=missing
        warn "Ansible in $ANSIBLE_VENV did not print the check-mode markers: the plugin could not prove its dry runs (the log has its output)"
    fi
}
ANSIBLE_MARKERS=''
