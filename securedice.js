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

        window.setTimeout(function () {
            btn.textContent = original;
        }, ms);
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

    function applyUI() {
        const diceCountA = qsId("dice_count");
        const dieTypeA = qsId("die_type");
        const modeA = qsId("mode");

        const rowB = qsId("roll-b");
        const diceCountB = qsId("dice_count_b");
        const dieTypeB = qsId("die_type_b");
        const modB = qsId("mod_b");
        const modeB = qsId("mode_b");

        if (!diceCountA || !dieTypeA || !modeA) {
            return;
        }

        const row1IsFudge = (String(dieTypeA.value || "") === "d6f");

        if (row1IsFudge) {
            modeA.value = "sum";
            modeA.disabled = true;
        } else {
            if (modeA.disabled) {
                modeA.value = "sum";
            }

            modeA.disabled = false;
        }

        const row1IsStunt = (!row1IsFudge && String(modeA.value || "") === "stunt");
        const row1IsWild = (!row1IsFudge && String(modeA.value || "") === "wild");

        if (row1IsStunt) {
            diceCountA.value = "3";
            dieTypeA.value = "d6";
            diceCountA.disabled = true;
            dieTypeA.disabled = true;
        } else {
            diceCountA.disabled = false;
            dieTypeA.disabled = false;
        }

        if (!row1IsStunt && row1IsWild) {
            const n = parseInt(String(diceCountA.value || ""), 10);

            if (!Number.isFinite(n) || n < 1) {
                diceCountA.value = "1";
            }

            if (String(dieTypeA.value || "") !== "d6") {
                dieTypeA.value = "d6";
            }

            dieTypeA.disabled = true;
        }

        const hideRowB = (row1IsFudge || row1IsWild || row1IsStunt);

        if (rowB) {
            rowB.classList.toggle("hidden", hideRowB);
        }

        if (diceCountB) {
            diceCountB.disabled = hideRowB;
        }

        if (dieTypeB) {
            dieTypeB.disabled = hideRowB;
        }

        if (modB) {
            modB.disabled = hideRowB;
        }

        if (modeB) {
            modeB.disabled = hideRowB;
        }

        setDropModeOptionsEnabled(modeA, getAbsIntValue(diceCountA) >= 2);
        setDropModeOptionsEnabled(modeB, getAbsIntValue(diceCountB) >= 2);

        if (hideRowB) {
            setRowBDefaults(diceCountB, dieTypeB, modeB, modB);
        }
    }

    function wireDiceUi() {
        const dieTypeA = qsId("die_type");
        const modeA = qsId("mode");
        const modeB = qsId("mode_b");
        const diceCountA = qsId("dice_count");
        const diceCountB = qsId("dice_count_b");

        if (dieTypeA) {
            dieTypeA.addEventListener("change", applyUI);
        }

        if (modeA) {
            modeA.addEventListener("change", applyUI);
        }

        if (diceCountA) {
            diceCountA.addEventListener("change", applyUI);
        }

        if (diceCountB) {
            diceCountB.addEventListener("change", applyUI);
        }

        if (modeB) {
            modeB.addEventListener("change", applyUI);
        }

        applyUI();
    }

    function prepareFormForReset() {
        const diceCountA = qsId("dice_count");
        const dieTypeA = qsId("die_type");
        const modeA = qsId("mode");

        const rowB = qsId("roll-b");
        const diceCountB = qsId("dice_count_b");
        const dieTypeB = qsId("die_type_b");
        const modB = qsId("mod_b");
        const modeB = qsId("mode_b");

        if (diceCountA) {
            diceCountA.disabled = false;
        }

        if (dieTypeA) {
            dieTypeA.disabled = false;
        }

        if (modeA) {
            modeA.disabled = false;
        }

        if (rowB) {
            rowB.classList.remove("hidden");
        }

        if (diceCountB) {
            diceCountB.disabled = false;
        }

        if (dieTypeB) {
            dieTypeB.disabled = false;
        }

        if (modB) {
            modB.disabled = false;
        }

        if (modeB) {
            modeB.disabled = false;
        }
    }

    function validateFormBeforeSubmit(event) {
        const diceCountA = qsId("dice_count");
        const modeA = qsId("mode");
        const diceCountB = qsId("dice_count_b");
        const modeB = qsId("mode_b");

        if (modeA && isDropMode(String(modeA.value || "")) && getAbsIntValue(diceCountA) < 2) {
            event.preventDefault();
            alert("First roll: Drop modes require at least 2 dice.");
            return;
        }

        if (modeB && !modeB.disabled && isDropMode(String(modeB.value || "")) && getAbsIntValue(diceCountB) < 2) {
            event.preventDefault();
            alert("Second roll: Drop modes require at least 2 dice.");
        }
    }

    function wireFloatingActions() {
        const form = qsId("sd2-form");
        const copyBtn = qsId("sd2-copy-url");
        const resetBtn = qsId("sd2-reset");

        if (form) {
            form.addEventListener("submit", validateFormBeforeSubmit);
        }

        if (copyBtn) {
            copyBtn.addEventListener("click", function () {
                const url = buildPresetUrl();

                copyToClipboard(url)
                    .then(function () {
                        flashButtonText(copyBtn, "Copied!", 900);
                    })
                    .catch(function () {
                        flashButtonText(copyBtn, "Copy Failed", 1200);
                    });
            });
        }

        if (resetBtn && form) {
            resetBtn.addEventListener("click", function () {
                prepareFormForReset();
                form.reset();
                applyUI();
                flashButtonText(resetBtn, "Reset!", 900);
            });
        }
    }

    function wireResultsCopyButtons() {
        const jsonTa = qsId("json-output");
        const copyJson = qsId("copy-json");
        const copyVerificationUrl = qsId("copy-verification-url");

        if (copyJson && jsonTa) {
            copyJson.addEventListener("click", function () {
                const txt = String(jsonTa.value || "");

                copyToClipboard(txt)
                    .then(function () {
                        flashButtonText(copyJson, "Copied!", 900);
                    })
                    .catch(function () {
                        flashButtonText(copyJson, "Copy Failed", 1200);
                    });
            });
        }

        if (copyVerificationUrl) {
            copyVerificationUrl.addEventListener("click", function () {
                const txt = String(copyVerificationUrl.getAttribute("data-copy") || "");

                copyToClipboard(txt)
                    .then(function () {
                        flashButtonText(copyVerificationUrl, "Copied!", 900);
                    })
                    .catch(function () {
                        flashButtonText(copyVerificationUrl, "Copy Failed", 1200);
                    });
            });
        }
    }

    function init() {
        wireDiceUi();
        wireFloatingActions();
        wireResultsCopyButtons();
    }

    document.addEventListener("DOMContentLoaded", init);
})();
