export function insertCommentImage(url) {
    const myField = document.getElementById('comment');
    if (!myField || myField.type != 'textarea') {
        return false;
    }
    const tag = `[img]${url}[/img]`;
    if (myField.selectionStart || myField.selectionStart == '0') {
        let startPos = myField.selectionStart,
            endPos = myField.selectionEnd,
            cursorPos = endPos;
        myField.value = myField.value.substring(0, startPos) + tag + myField.value.substring(endPos, myField.value.length);
        cursorPos += tag.length;
        myField.focus();
        myField.selectionStart = cursorPos;
        myField.selectionEnd = cursorPos;
    } else {
        myField.value += tag;
        myField.focus();
    }
}
