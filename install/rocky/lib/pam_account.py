"""install/rocky/lib/pam_account.py SERVICE USER: PAM's account check of USER for SERVICE, as the service makes it
before it runs anything for USER: pam_start(SERVICE, USER), the TTY item a cron daemon sets ("cron"), then
pam_acct_mgmt. crond does this for every job of /etc/cron.d/librenms; a check that fails there means cron runs none
of them (journalctl -u crond: "FAILED to authorize user with PAM"). Prints "ok", or PAM's answer and exits with 1.
Run it as root, as crond runs.
"""
import ctypes
import ctypes.util
import sys

PAM_TTY = 3


class PamMessage(ctypes.Structure):
    _fields_ = [('msg_style', ctypes.c_int), ('msg', ctypes.c_char_p)]


class PamResponse(ctypes.Structure):
    _fields_ = [('resp', ctypes.c_char_p), ('resp_retcode', ctypes.c_int)]


CONVERSATION = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_int, ctypes.POINTER(ctypes.POINTER(PamMessage)),
                                ctypes.POINTER(ctypes.POINTER(PamResponse)), ctypes.c_void_p)


class PamConv(ctypes.Structure):
    _fields_ = [('conv', CONVERSATION), ('appdata_ptr', ctypes.c_void_p)]


def no_questions(count, messages, responses, data):
    # an account check asks nothing; cron could not answer anyway
    return 19  # PAM_CONV_ERR


def main():
    if len(sys.argv) != 3:
        print('usage: pam_account.py SERVICE USER', file=sys.stderr)
        return 2
    service, user = sys.argv[1], sys.argv[2]
    pam = ctypes.CDLL(ctypes.util.find_library('pam') or 'libpam.so.0')
    pam.pam_strerror.restype = ctypes.c_char_p
    callback = CONVERSATION(no_questions)
    conversation = PamConv(callback, None)
    handle = ctypes.c_void_p()
    rc = pam.pam_start(service.encode(), user.encode(), ctypes.byref(conversation), ctypes.byref(handle))
    if rc != 0:
        print('pam_start(%s, %s) failed (%d)' % (service, user, rc))
        return 1
    pam.pam_set_item(handle, PAM_TTY, ctypes.c_char_p(b'cron'))
    rc = pam.pam_acct_mgmt(handle, 0)
    message = pam.pam_strerror(handle, rc).decode('utf-8', 'replace')
    pam.pam_end(handle, rc)
    if rc != 0:
        print('%s (PAM code %d)' % (message, rc))
        return 1
    print('ok')
    return 0


if __name__ == '__main__':
    sys.exit(main())
