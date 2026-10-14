/**
 * Shared "show/hide password" toggle and strong-password generator, used by
 * the staff login page and the Add User / Reset Password admin screens.
 * No dependencies (plain DOM) so it works on the standalone login page too,
 * which doesn't load jQuery-dependent app bundles the same way the
 * authenticated layout does.
 */
window.PasswordTools = (function () {
    // Ambiguous characters (0/O, 1/l/I) left out so a generated password is
    // easier to read back correctly over the phone or in chat.
    var CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';

    function generate(length) {
        length = length || 14;
        var values = new Uint32Array(length);
        (window.crypto || window.msCrypto).getRandomValues(values);
        var result = '';
        for (var i = 0; i < length; i++) {
            result += CHARSET[values[i] % CHARSET.length];
        }
        return result;
    }

    /**
     * toggleBtn shows/hides every input in `inputs` together (e.g. password
     * + confirm), swapping one eye icon. icon.masked/icon.visible are the
     * CSS classes to show on iconEl while the password is masked (the
     * "eye" -- click to reveal) vs. currently visible as plain text (the
     * "eye-slash" -- click to hide) -- e.g. {masked:'mdi-eye-outline',
     * visible:'mdi-eye-off-outline'} -- passed explicitly, not guessed
     * from an icon font, so this works the same whether the page loads
     * Material Design Icons or Font Awesome.
     */
    function bindToggle(toggleBtn, inputs, iconEl, icon) {
        if (!toggleBtn || !inputs.length) {
            return;
        }
        toggleBtn.addEventListener('click', function () {
            var willBeVisible = inputs[0].type === 'password';
            inputs.forEach(function (input) {
                input.type = willBeVisible ? 'text' : 'password';
            });
            setIcon(iconEl, icon, willBeVisible);
        });
    }

    /** generateBtn fills every input in `inputs` with the same fresh password and reveals it (a hidden generated password is useless -- the admin needs to actually read it to pass it on). */
    function bindGenerate(generateBtn, inputs, iconEl, icon) {
        if (!generateBtn || !inputs.length) {
            return;
        }
        generateBtn.addEventListener('click', function () {
            var password = generate(14);
            inputs.forEach(function (input) {
                input.type = 'text';
                input.value = password;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
            setIcon(iconEl, icon, true);
        });
    }

    function setIcon(iconEl, icon, isVisible) {
        if (!iconEl || !icon) {
            return;
        }
        iconEl.classList.toggle(icon.masked, !isVisible);
        iconEl.classList.toggle(icon.visible, isVisible);
    }

    return { generate: generate, bindToggle: bindToggle, bindGenerate: bindGenerate };
})();
