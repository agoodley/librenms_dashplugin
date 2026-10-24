# Completion is trusted installer code; the wrapper drops root before querying LibreNMS.
_lnms_completion() {
    local candidates
    candidates=$(COMP_CURRENT="${2}" COMP_PREVIOUS="${3}" COMP_LINE="${COMP_LINE}" /usr/bin/lnms list:bash-completion) || return
    COMPREPLY=()
    [[ -z $candidates ]] || read -r -a COMPREPLY <<<"$candidates"
}
complete -F _lnms_completion lnms
