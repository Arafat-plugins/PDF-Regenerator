/* PDF Regenerator — Preview & Edit page: Branding strip (logo, colors), website-profile chip
 * and the "This looks like …" banner (Part D §6, §14). Changes go through the editor's save
 * queue (data.php); the logo file itself is uploaded to logo.php.
 */
(function () {
    'use strict';

    var R = window.PdfRegen;
    var root = document.querySelector('[data-review]');
    if (!R || !root) { return; }

    function start() {
        var ed = R.editor;
        if (!ed) { return; }
        var id = root.getAttribute('data-id');
        var card = document.querySelector('[data-branding]');
        var chip = document.querySelector('[data-profile-chip]');
        var matchBox = document.querySelector('[data-profile-match]');
        var HEX = /^#[0-9a-f]{6}$/i;

        function q(sel) { return card ? card.querySelector(sel) : null; }

        var logoImg = q('[data-logo-img]');
        var logoNone = q('[data-logo-none]');
        var logoThumb = q('[data-logo-thumb]');
        var logoLabel = q('[data-logo-label]');
        var logoInput = q('[data-logo-input]');
        var logoUpload = q('[data-logo-upload]');
        var logoUploadText = q('[data-logo-upload-text]');
        var logoRestore = q('[data-logo-restore]');
        var logoRemove = q('[data-logo-remove]');
        var logoError = q('[data-logo-error]');
        var swatches = card ? Array.prototype.slice.call(card.querySelectorAll('[data-palette]')) : [];
        var paletteName = q('[data-palette-name]');
        var customPanel = q('[data-custom-panel]');
        var customFill = q('[data-custom-fill]');
        var customApply = q('[data-custom-apply]');
        var customError = q('[data-custom-error]');
        var contrast = q('[data-contrast]');
        var note = q('[data-brand-note]');
        var pickers = {};
        var hexes = {};
        if (card) {
            card.querySelectorAll('[data-color-picker]').forEach(function (n) { pickers[n.getAttribute('data-color-picker')] = n; });
            card.querySelectorAll('[data-color-hex]').forEach(function (n) { hexes[n.getAttribute('data-color-hex')] = n; });
        }
        var uploading = false;

        function brand() {
            var m = ed.model();
            return m && m.branding ? m.branding : null;
        }

        // --------------------------------------------------------- rendering
        function luminance(hex) {
            var c = [1, 3, 5].map(function (i) {
                var v = parseInt(hex.substr(i, 2), 16) / 255;
                return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
        }

        function fill(node, a, b) {
            node.style.background = 'linear-gradient(135deg, ' + a + ' 0 50%, ' + b + ' 50% 100%)';
        }

        function render(m) {
            var b = m && m.branding;
            if (!b) { return; }

            // profile chip + note
            var p = b.profile || { mode: 'none' };
            if (chip) {
                chip.className = 'chip chip--profile chip--' + p.mode;
                chip.querySelector('[data-profile-chip-text]').textContent = p.mode === 'existing' ? 'Profile: ' + p.name : (p.mode === 'new' ? 'New website: ' + p.name : 'No website profile');
                chip.querySelector('[data-profile-change]').textContent = p.mode === 'none' ? 'Choose' : 'Change';
            }
            if (note) {
                note.textContent = p.mode === 'existing' ? 'Changes will be saved to the “' + p.name + '” profile.' : (p.mode === 'new' ? 'Saved as the new “' + p.name + '” profile when you generate.' : '');
                note.hidden = p.mode === 'none';
            }

            // auto-match banner
            if (matchBox) {
                matchBox.hidden = !b.match;
                if (b.match) {
                    matchBox.querySelector('[data-match-name]').textContent = b.match.name;
                    matchBox.querySelector('[data-match-host]').textContent = b.match.host;
                }
            }

            if (!card) { return; }

            // logo
            var logo = b.logo;
            if (logo.url) {
                if (logoImg.getAttribute('src') !== logo.url) { logoImg.src = logo.url; }
                logoImg.hidden = false;
                logoNone.hidden = true;
            } else {
                logoImg.hidden = true;
                logoImg.removeAttribute('src');
                logoNone.hidden = false;
            }
            logoThumb.classList.toggle('is-empty', !logo.url);
            logoThumb.classList.toggle('is-custom', logo.mode === 'draft' || logo.mode === 'profile');
            logoLabel.textContent = logo.label;
            logoUploadText.textContent = logo.mode === 'draft' || logo.mode === 'profile' ? 'Replace' : 'Upload logo';
            logoRemove.hidden = !logo.can_remove;
            logoRestore.hidden = !logo.can_restore;

            // colors
            swatches.forEach(function (btn) {
                var on = btn.getAttribute('data-palette') === b.palette;
                btn.classList.toggle('is-selected', on);
                btn.setAttribute('aria-checked', on ? 'true' : 'false');
                btn.tabIndex = on ? 0 : -1;
            });
            if (paletteName && m.template) { paletteName.textContent = m.template.palette_label; }
            if (b.palette === 'custom') {
                fill(customFill, b.custom.accent, b.custom.cover);
                customFill.textContent = '';
            } else if (!customFill.querySelector('svg')) {
                customFill.style.background = '';
                customFill.innerHTML = R.icon('plus');
            }
            if (!customPanel.contains(document.activeElement)) {
                setColor('accent', b.custom.accent);
                setColor('cover', b.custom.cover);
                if (b.custom.success) { setColor('success', b.custom.success); }
            }
            if (b.palette !== 'custom' && !customPanel.contains(document.activeElement)) { customPanel.hidden = true; }
            updateContrast();
        }

        function updateContrast() {
            var b = brand();
            if (!b || !contrast) { return; }
            var typed = hexes.cover ? hexes.cover.value.trim() : '';
            if (/^[0-9a-f]{6}$/i.test(typed)) { typed = '#' + typed; }
            var cover = !customPanel.hidden && HEX.test(typed) ? typed : b.cover;
            contrast.hidden = !(HEX.test(cover) && luminance(cover) > 0.45);
        }

        ed.onModel(function (m) { render(m); });
        render(ed.model());

        // ------------------------------------------------------------ colors
        function choosePalette(btn) {
            var palette = btn.getAttribute('data-palette');
            if (palette === 'custom') {
                var open = customPanel.hidden;
                customPanel.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    customError.hidden = true;
                    hexes.accent.focus();
                    hexes.accent.select();
                }
                updateContrast();
                return;
            }
            if (btn.classList.contains('is-selected')) { return; }
            customPanel.hidden = true;
            swatches.forEach(function (s) {
                s.classList.toggle('is-selected', s === btn);
                s.setAttribute('aria-checked', s === btn ? 'true' : 'false');
            });
            if (paletteName) { paletteName.textContent = btn.getAttribute('title'); }
            ed.send('set_palette', { palette: palette });
        }

        swatches.forEach(function (btn, i) {
            btn.addEventListener('click', function () { choosePalette(btn); });
            btn.addEventListener('keydown', function (event) {
                var keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
                if (!keys[event.key]) { return; }
                event.preventDefault();
                var next = swatches[(i + keys[event.key] + swatches.length) % swatches.length];
                next.focus();
                if (next.getAttribute('data-palette') !== 'custom') { choosePalette(next); }
            });
        });

        function setColor(which, hex) {
            if (!hexes[which] || !HEX.test(hex)) { return; }
            hexes[which].value = hex.toLowerCase();
            pickers[which].value = hex.toLowerCase();
            hexes[which].removeAttribute('aria-invalid');
        }

        Object.keys(pickers).forEach(function (which) {
            pickers[which].addEventListener('input', function () {
                hexes[which].value = pickers[which].value;
                hexes[which].removeAttribute('aria-invalid');
                customError.hidden = true;
                updateContrast();
            });
            hexes[which].addEventListener('input', function () {
                var v = hexes[which].value.trim();
                if (/^[0-9a-f]{6}$/i.test(v)) { v = '#' + v; }
                if (HEX.test(v)) {
                    pickers[which].value = v.toLowerCase();
                    hexes[which].removeAttribute('aria-invalid');
                    customError.hidden = true;
                }
                updateContrast();
            });
            hexes[which].addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); applyCustom(); }
                if (event.key === 'Escape') { event.preventDefault(); customPanel.hidden = true; }
            });
        });

        function applyCustom() {
            var values = {};
            var bad = null;
            ['accent', 'cover', 'success'].forEach(function (which) {
                if (!hexes[which]) { return; }
                var v = hexes[which].value.trim();
                if (/^[0-9a-f]{6}$/i.test(v)) { v = '#' + v; }
                if (!HEX.test(v)) {
                    hexes[which].setAttribute('aria-invalid', 'true');
                    bad = bad || which;
                } else {
                    values[which] = v.toLowerCase();
                    setColor(which, v);
                }
            });
            if (bad) {
                customError.textContent = ({ accent: 'Accent color', cover: 'Cover color', success: 'Success color' })[bad] + ' must be a color like #2563eb.';
                customError.hidden = false;
                hexes[bad].focus();
                return;
            }
            customError.hidden = true;
            customApply.disabled = true;
            ed.send('set_custom_colors', values, { quiet: true }).then(function (res) {
                customApply.disabled = false;
                if (!res.ok) {
                    customError.textContent = res.message || 'The colors could not be saved.';
                    customError.hidden = false;
                    return;
                }
                customPanel.hidden = true;
                swatches.forEach(function (sw) { if (sw.getAttribute('data-palette') === 'custom') { sw.setAttribute('aria-expanded', 'false'); } });
                R.toast(res.data.result && res.data.result.light_cover ? 'Custom colors saved. White cover text may be hard to read.' : 'Custom colors saved.', res.data.result && res.data.result.light_cover ? 'warning' : 'success');
            });
        }
        if (customApply) { customApply.addEventListener('click', applyCustom); }

        // -------------------------------------------------------------- logo
        function logoFail(message) {
            logoError.textContent = message;
            logoError.hidden = false;
        }

        if (logoUpload) {
            logoUpload.addEventListener('click', function () {
                if (uploading) { return; }
                logoInput.value = '';
                logoInput.click();
            });
        }
        if (logoInput) {
            logoInput.addEventListener('change', function () {
                var file = logoInput.files && logoInput.files[0];
                if (!file) { return; }
                logoError.hidden = true;
                var max = parseInt(logoInput.getAttribute('data-max-size'), 10) || 0;
                if (!/\.(png|jpe?g|webp|svg)$/i.test(file.name) || (file.type && !/^image\/(png|jpeg|webp|svg\+xml)$/.test(file.type))) {
                    logoFail('Only JPG, PNG, WebP or SVG logos are allowed.');
                    return;
                }
                if (max && file.size > max) {
                    logoFail('This logo is too large. The maximum size is ' + Math.round(max / 1048576 * 10) / 10 + ' MB.');
                    return;
                }
                uploading = true;
                logoUpload.disabled = true;
                logoThumb.classList.add('is-busy');
                var data = new FormData();
                data.append('action', 'upload');
                data.append('id', id);
                data.append('logo', file, file.name);
                R.api(logoInput.getAttribute('data-upload-url'), { method: 'POST', body: data }).then(function (res) {
                    uploading = false;
                    logoUpload.disabled = false;
                    logoThumb.classList.remove('is-busy');
                    if (!res.ok) {
                        logoFail(res.message || 'The logo could not be uploaded. Please try again.');
                        return;
                    }
                    ed.send('load', {}).then(function () {
                        if (R.preview) { R.preview.reload(); }
                    });
                    R.toast('Logo uploaded — it is used in this report' + (brand() && brand().profile.mode !== 'none' ? ' and saved to the profile when you generate.' : '.'), 'success');
                });
            });
        }

        if (logoRemove) {
            logoRemove.addEventListener('click', function () {
                var b = brand();
                var wasProfile = b && b.logo.mode === 'profile';
                logoError.hidden = true;
                ed.send('remove_logo', {}).then(function (res) {
                    if (!res.ok) { return; }
                    if (wasProfile) {
                        R.toast('Logo removed for this report. The profile keeps it until you generate.', 'info', {
                            label: 'Undo',
                            onClick: function () { ed.send('restore_logo', {}); }
                        });
                    } else {
                        R.toast('Logo removed — the template logo is used.', 'success');
                    }
                });
            });
        }
        if (logoRestore) {
            logoRestore.addEventListener('click', function () {
                logoError.hidden = true;
                ed.send('restore_logo', {});
            });
        }

        // ---------------------------------------------------- website profile
        function applyProfile(profileId) {
            return ed.flush().then(function () {
                return ed.send('apply_profile', { profile: profileId }, { quiet: true });
            }).then(function (res) {
                if (res.ok) {
                    var r = res.data.result || {};
                    R.toast('Profile “' + (r.name || '') + '” applied — template, colors, logo and texts were filled in.', 'success');
                }
                return res;
            });
        }

        var changeBtn = chip ? chip.querySelector('[data-profile-change]') : null;
        if (changeBtn && R.profileChooser) {
            changeBtn.addEventListener('click', function () {
                var b = brand();
                var p = b ? b.profile : { mode: 'none' };
                R.profileChooser.open({
                    title: 'Website Profile',
                    message: 'Choose which website this report belongs to. A saved profile fills in its template, colors, logo and texts; your edits to the extracted values stay.',
                    confirmLabel: 'Apply',
                    initial: p.mode === 'existing' ? { mode: 'existing', id: p.id } : (p.mode === 'new' ? { mode: 'new', name: p.name, url: p.url } : {}),
                    submit: function (choice) {
                        if (choice.mode === 'existing') {
                            if (p.mode === 'existing' && p.id === choice.id) { return { ok: true }; }
                            return applyProfile(choice.id).then(function (res) {
                                if (!res.ok && res.errors && res.errors[0] === 'stale') { R.profileChooser.refresh(); }
                                return res;
                            });
                        }
                        return ed.flush().then(function () {
                            return ed.send('set_new_website', { name: choice.name, url: choice.url }, { quiet: true });
                        }).then(function (res) {
                            if (res.ok) { R.toast('This report is for the new website “' + choice.name + '”.', 'success'); }
                            else if (res.data && !res.data.field) { res.data.field = 'website_name'; }
                            return res;
                        });
                    }
                });
            });
        }

        if (matchBox) {
            matchBox.querySelector('[data-match-apply]').addEventListener('click', function (event) {
                var b = brand();
                if (!b || !b.match) { return; }
                event.currentTarget.disabled = true;
                var btn = event.currentTarget;
                applyProfile(b.match.id).then(function () { btn.disabled = false; });
            });
            matchBox.querySelector('[data-match-ignore]').addEventListener('click', function () {
                var b = brand();
                if (!b || !b.match) { return; }
                matchBox.hidden = true;
                ed.send('dismiss_profile_hint', { profile: b.match.id });
            });
        }

        // keep the chooser list current when a profile is renamed / deleted in a dialog
        if (R.profileChooser) {
            R.profileChooser.onProfiles(function () { ed.send('load', {}); });
        }
    }

    if (R.editor) {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
    }
})();
