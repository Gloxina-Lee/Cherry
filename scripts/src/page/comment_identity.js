import { get_gravatar } from './gravatar'

export default function initCommentIdentity() {
    const author = document.querySelector('input#author')
    const email = document.querySelector('input#email')
    const url = document.querySelector('input#url')
    const avatar = document.querySelector('div.comment-user-avatar img')
    if (!author || !email || !url || !avatar) return
    const defaultAvatar = avatar.src

    // Discard values saved by the former QQ autocomplete before restoring identity fields.
    const wasQQ = localStorage.getItem('is_user_qq') === 'yes' || Boolean(localStorage.getItem('user_qq_email'))
    if (wasQQ) {
        for (const key of ['user_author', 'user_email', 'user_url', 'user_avatar']) {
            localStorage.removeItem(key)
        }
        author.value = email.value = url.value = ''
    }
    for (const key of ['user_qq', 'user_qq_email', 'is_user_qq']) {
        localStorage.removeItem(key)
    }

    if (localStorage.getItem('user_author')) author.value = localStorage.getItem('user_author')
    if (localStorage.getItem('user_email')) email.value = localStorage.getItem('user_email')
    if (localStorage.getItem('user_url')) url.value = localStorage.getItem('user_url')
    if (localStorage.getItem('user_avatar')) avatar.src = localStorage.getItem('user_avatar')

    author.addEventListener('blur', () => {
        if (author.value) localStorage.setItem('user_author', author.value)
        else localStorage.removeItem('user_author')
    })
    url.addEventListener('blur', () => {
        if (url.value) localStorage.setItem('user_url', url.value)
        else localStorage.removeItem('user_url')
    })
    email.addEventListener('blur', () => {
        if (email.value) {
            localStorage.setItem('user_email', email.value)
            avatar.src = get_gravatar(email.value, 80)
            localStorage.setItem('user_avatar', avatar.src)
        } else {
            localStorage.removeItem('user_email')
            localStorage.removeItem('user_avatar')
            avatar.src = defaultAvatar
        }
    })
}
