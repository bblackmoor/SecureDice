// securedice.js

(function () {
    "use strict";

    function qsId(id) {
        return document.getElementById(id);
    }

    function flashButtonText(btn, text, ms) {
        if (!btn) {
            return;
        }

        const original = btn.textContent;

        btn.textContent = text;
        announceAction(text);

        window.setTimeout(function () {
            btn.textContent = original;
        }, ms);
    }

    function announceAction(text) {
        let status = qsId("action-status");

        if (!status) {
            status = document.createElement("div");
            status.id = "action-status";
            status.className = "sr-only";
            status.setAttribute("role", "status");
            status.setAttribute("aria-live", "polite");
            status.setAttribute("aria-atomic", "true");
            document.body.appendChild(status);
        }

        status.textContent = "";
        window.setTimeout(function () {
            status.textContent = text;
        }, 20);
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            try {
                const ta = document.createElement("textarea");

                ta.value = text;
                ta.setAttribute("readonly", "readonly");
                ta.style.position = "fixed";
                ta.style.left = "-9999px";

                document.body.appendChild(ta);
                ta.select();

                const ok = document.execCommand("copy");

                document.body.removeChild(ta);

                if (!ok) {
                    reject(new Error("copy failed"));
                    return;
                }

                resolve();
            } catch (e) {
                reject(e);
            }
        });
    }

    function mapModeToPreset(modeValue) {
        if (modeValue === "drop_lowest") {
            return "lowest";
        }

        if (modeValue === "drop_highest") {
            return "highest";
        }

        if (modeValue === "wild") {
            return "wild";
        }

        if (modeValue === "stunt") {
            return "stunt";
        }

        return "none";
    }

    function isDropMode(modeValue) {
        return (modeValue === "drop_lowest" || modeValue === "drop_highest");
    }

    function setDropModeOptionsEnabled(modeEl, enabled) {
        if (!modeEl) {
            return;
        }

        Array.prototype.forEach.call(modeEl.options, function (option) {
            if (isDropMode(String(option.value || ""))) {
                option.disabled = !enabled;
            }
        });

        if (!enabled && isDropMode(String(modeEl.value || ""))) {
            modeEl.value = "sum";
        }
    }

    function getAbsIntValue(el) {
        if (!el) {
            return 0;
        }

        const n = parseInt(String(el.value || ""), 10);

        if (!Number.isFinite(n)) {
            return 0;
        }

        return Math.abs(n);
    }

    function getInputValue(idA, idB) {
        const el = qsId(idA) || qsId(idB);

        if (!el) {
            return "";
        }

        return String(el.value || "");
    }

    function getSelectValue(id) {
        const el = qsId(id);

        if (!el) {
            return "";
        }

        return String(el.value || "");
    }

    function getChecked(id) {
        const el = qsId(id);

        if (!el) {
            return false;
        }

        return Boolean(el.checked);
    }

    function buildPresetUrl() {
        const aq = getSelectValue("dice_count");
        const dieA = getSelectValue("die_type");
        const am = getInputValue("mod").trim();
        const ad = mapModeToPreset(getSelectValue("mode"));

        const bq = getSelectValue("dice_count_b");
        const dieB = getSelectValue("die_type_b");
        const bm = getInputValue("mod_b").trim();
        const bd = mapModeToPreset(getSelectValue("mode_b"));

        const dt = getSelectValue("repeat");
        const sdt = getChecked("sort_results") ? "1" : "";

        let as = "";
        let af = "";

        if (dieA === "d6f") {
            as = "6";
            af = "1";
        } else if (/^d\d+$/.test(dieA)) {
            as = dieA.replace(/^d/, "");
        }

        let bs = "";

        if (/^d\d+$/.test(dieB)) {
            bs = dieB.replace(/^d/, "");
        }

        const params = new URLSearchParams();

        if (aq !== "") {
            params.set("aq", aq);
        }

        if (as !== "") {
            params.set("as", as);
        }

        if (am !== "") {
            params.set("am", am);
        }

        if (ad !== "") {
            params.set("ad", ad);
        }

        if (af !== "") {
            params.set("af", af);
        }

        if (bq !== "") {
            params.set("bq", bq);
        }

        if (bs !== "") {
            params.set("bs", bs);
        }

        if (bm !== "") {
            params.set("bm", bm);
        }

        if (bd !== "") {
            params.set("bd", bd);
        }

        if (dt !== "") {
            params.set("dt", dt);
        }

        if (sdt !== "") {
            params.set("sdt", sdt);
        }

        const url = new URL(window.location.href);

        url.search = params.toString();

        return url.toString();
    }

    function getPrimaryDiceControls() {
        return {
            diceCount: qsId("dice_count"),
            dieType: qsId("die_type"),
            mode: qsId("mode")
        };
    }

    function getSecondaryDiceControls() {
        return {
            rows: document.querySelectorAll(".roll-b, .dice-section-title-b"),
            diceCount: qsId("dice_count_b"),
            dieType: qsId("die_type_b"),
            modifier: qsId("mod_b"),
            mode: qsId("mode_b")
        };
    }

    function setSecondaryControlsHidden(controls, hidden) {
        Array.prototype.forEach.call(controls.rows, function (row) {
            row.hidden = hidden;
        });

        [controls.diceCount, controls.dieType, controls.modifier, controls.mode]
            .forEach(function (control) {
                if (control) {
                    control.disabled = hidden;
                }
            });
    }

    function setRowBDefaults(diceCountB, dieTypeB, modeB, modB) {
        if (diceCountB) {
            diceCountB.value = "0";
        }

        if (dieTypeB) {
            dieTypeB.value = "d6";
        }

        if (modeB) {
            modeB.value = "sum";
        }

        if (modB) {
            modB.value = "0";
        }
    }

    function applyPrimaryDiceRules(controls) {
        const row1IsFudge = (String(controls.dieType.value || "") === "d6f");

        if (row1IsFudge) {
            controls.mode.value = "sum";
            controls.mode.disabled = true;
        } else {
            if (controls.mode.disabled) {
                controls.mode.value = "sum";
            }

            controls.mode.disabled = false;
        }

        const row1IsStunt = (!row1IsFudge && String(controls.mode.value || "") === "stunt");
        const row1IsWild = (!row1IsFudge && String(controls.mode.value || "") === "wild");

        if (row1IsStunt) {
            controls.diceCount.value = "3";
            controls.dieType.value = "d6";
            controls.diceCount.disabled = true;
            controls.dieType.disabled = true;
        } else {
            controls.diceCount.disabled = false;
            controls.dieType.disabled = false;
        }

        if (!row1IsStunt && row1IsWild) {
            const n = parseInt(String(controls.diceCount.value || ""), 10);

            if (!Number.isFinite(n) || n < 1) {
                controls.diceCount.value = "1";
            }

            if (String(controls.dieType.value || "") !== "d6") {
                controls.dieType.value = "d6";
            }

            controls.dieType.disabled = true;
        }

        setDropModeOptionsEnabled(controls.mode, getAbsIntValue(controls.diceCount) >= 2);

        return row1IsFudge || row1IsWild || row1IsStunt;
    }

    function applySecondaryDiceRules(controls, hidden) {
        setSecondaryControlsHidden(controls, hidden);
        setDropModeOptionsEnabled(controls.mode, getAbsIntValue(controls.diceCount) >= 2);

        if (hidden) {
            setRowBDefaults(controls.diceCount, controls.dieType, controls.mode, controls.modifier);
        }
    }

    function applyUI() {
        const primary = getPrimaryDiceControls();

        if (!primary.diceCount || !primary.dieType || !primary.mode) {
            return;
        }

        const secondary = getSecondaryDiceControls();
        const hideSecondary = applyPrimaryDiceRules(primary);

        applySecondaryDiceRules(secondary, hideSecondary);
    }

    function wireControlChange(control, beforeApply) {
        if (!control) {
            return;
        }

        control.addEventListener("change", function () {
            if (beforeApply) {
                beforeApply();
            }

            applyUI();
        });
    }

    function clearValidity(control) {
        if (control) {
            control.setCustomValidity("");
        }
    }

    function wireDiceUi() {
        const primary = getPrimaryDiceControls();
        const secondary = getSecondaryDiceControls();

        wireControlChange(primary.dieType);
        wireControlChange(primary.mode, function () {
            clearValidity(primary.mode);
        });
        wireControlChange(primary.diceCount, function () {
            clearValidity(primary.mode);
        });
        wireControlChange(secondary.diceCount, function () {
            clearValidity(secondary.mode);
        });
        wireControlChange(secondary.mode, function () {
            clearValidity(secondary.mode);
        });

        applyUI();
    }

    function prepareFormForReset() {
        const primary = getPrimaryDiceControls();
        const secondary = getSecondaryDiceControls();

        [primary.diceCount, primary.dieType, primary.mode].forEach(function (control) {
            if (control) {
                control.disabled = false;
            }
        });

        setSecondaryControlsHidden(secondary, false);
    }

    function validateFormBeforeSubmit(event) {
        const diceCountA = qsId("dice_count");
        const modeA = qsId("mode");
        const diceCountB = qsId("dice_count_b");
        const modeB = qsId("mode_b");

        if (modeA && isDropMode(String(modeA.value || "")) && getAbsIntValue(diceCountA) < 2) {
            event.preventDefault();
            modeA.setCustomValidity("Primary roll drop modes require at least 2 dice.");
            modeA.reportValidity();
            modeA.focus();
            return;
        }

        if (modeB && !modeB.disabled && isDropMode(String(modeB.value || "")) && getAbsIntValue(diceCountB) < 2) {
            event.preventDefault();
            modeB.setCustomValidity("Secondary roll drop modes require at least 2 dice.");
            modeB.reportValidity();
            modeB.focus();
        }
    }

    function wireFloatingActions() {
        const form = qsId("sd2-form");
        const copyBtn = qsId("sd2-copy-url");
        const resetBtn = qsId("sd2-reset");

        if (form) {
            form.addEventListener("submit", validateFormBeforeSubmit);
        }

        wireCopyButton(copyBtn, buildPresetUrl);

        if (resetBtn && form) {
            resetBtn.addEventListener("click", function () {
                prepareFormForReset();
                form.reset();
                applyUI();
                flashButtonText(resetBtn, "Reset!", 900);
            });
        }
    }

    function wireCopyButton(button, getText) {
        if (!button) {
            return;
        }

        button.addEventListener("click", function () {
            copyToClipboard(getText())
                .then(function () {
                    flashButtonText(button, "Copied!", 900);
                })
                .catch(function () {
                    flashButtonText(button, "Copy Failed", 1200);
                });
        });
    }

    function wireResultsCopyButtons() {
        const jsonTa = qsId("json-output");
        const copyJson = qsId("copy-json");

        if (copyJson && jsonTa) {
            wireCopyButton(copyJson, function () {
                return String(jsonTa.value || "");
            });
        }

        Array.prototype.forEach.call(document.querySelectorAll("[data-copy]"), function (copyButton) {
            wireCopyButton(copyButton, function () {
                return String(copyButton.getAttribute("data-copy") || "");
            });
        });
    }

    function wireConfirmationForms() {
        Array.prototype.forEach.call(document.querySelectorAll("form[data-confirm]"), function (form) {
            form.addEventListener("submit", function (event) {
                if (!window.confirm(String(form.getAttribute("data-confirm") || "Continue?"))) {
                    event.preventDefault();
                }
            });
        });
    }

    function init() {
        wireDiceUi();
        wireFloatingActions();
        wireResultsCopyButtons();
        wireConfirmationForms();
    }

    document.addEventListener("DOMContentLoaded", init);
})();
