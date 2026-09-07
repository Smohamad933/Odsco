// Simple Editor - نسخه مینیمال
class SimpleEditor {
    constructor(textareaId) {
        this.textarea = document.getElementById(textareaId);
        if (!this.textarea) return;
        
        this.createEditor();
    }
    
    createEditor() {
        // ساخت toolbar
        const toolbar = document.createElement('div');
        toolbar.className = 'simple-editor-toolbar';
        toolbar.innerHTML = `
            <button type="button" data-cmd="bold" title="بولد (Ctrl+B)"><b>B</b></button>
            <button type="button" data-cmd="italic" title="ایتالیک (Ctrl+I)"><i>I</i></button>
            <button type="button" data-cmd="underline" title="زیرخط (Ctrl+U)"><u>U</u></button>
            <span class="separator"></span>
            <button type="button" data-cmd="insertUnorderedList" title="لیست">• لیست</button>
            <button type="button" data-cmd="insertOrderedList" title="لیست شماره‌دار">۱. لیست</button>
            <span class="separator"></span>
            <button type="button" data-cmd="formatBlock" data-value="h2" title="تیتر">H2</button>
            <button type="button" data-cmd="formatBlock" data-value="h3" title="زیرتیتر">H3</button>
            <button type="button" data-cmd="formatBlock" data-value="p" title="پاراگراف">P</button>
            <span class="separator"></span>
            <button type="button" data-cmd="createLink" title="لینک">🔗</button>
            <button type="button" data-cmd="removeLink" title="حذف لینک">🔓</button>
            <button type="button" data-cmd="insertHorizontalRule" title="خط جداکننده">—</button>
            <span class="separator"></span>
            <button type="button" data-cmd="justifyRight" title="راست‌چین">⬅️</button>
            <button type="button" data-cmd="justifyCenter" title="وسط‌چین">⬌</button>
            <button type="button" data-cmd="justifyLeft" title="چپ‌چین">➡️</button>
        `;
        
        // ساخت contenteditable
        this.editorDiv = document.createElement('div');
        this.editorDiv.className = 'simple-editor-content';
        this.editorDiv.contentEditable = true;
        this.editorDiv.innerHTML = this.textarea.value;
        
        // جایگزینی textarea
        this.textarea.style.display = 'none';
        this.textarea.parentNode.insertBefore(toolbar, this.textarea);
        this.textarea.parentNode.insertBefore(this.editorDiv, this.textarea);
        
        // رویدادها
        toolbar.querySelectorAll('button[data-cmd]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const cmd = btn.dataset.cmd;
                const value = btn.dataset.value || null;
                
                if (cmd === 'createLink') {
                    const url = prompt('آدرس لینک را وارد کنید:', 'https://');
                    if (url) document.execCommand(cmd, false, url);
                } else if (cmd === 'formatBlock') {
                    document.execCommand(cmd, false, value);
                } else {
                    document.execCommand(cmd, false, null);
                }
                
                this.editorDiv.focus();
                this.syncContent();
            });
        });
        
        // sync محتوا
        this.editorDiv.addEventListener('input', () => this.syncContent());
        
        // فرم submit
        const form = this.textarea.closest('form');
        if (form) {
            form.addEventListener('submit', () => this.syncContent());
        }
    }
    
    syncContent() {
        this.textarea.value = this.editorDiv.innerHTML;
    }
    
    getContent() {
        return this.editorDiv.innerHTML;
    }
}