(() => {
  function digitsOnly(s) {
    return (s || "").replace(/[^\d]/g, "");
  }

  function formatWithCommas(digits) {
    if (!digits) return "";
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  }

  function countDigitsLeftOfCaret(value, caretPos) {
    const left = value.slice(0, caretPos);
    return digitsOnly(left).length;
  }

  function caretPosForDigitIndex(formattedValue, digitIndex) {
    if (digitIndex <= 0) return 0;
    let digitsSeen = 0;
    for (let i = 0; i < formattedValue.length; i++) {
      if (/\d/.test(formattedValue[i])) {
        digitsSeen++;
        if (digitsSeen === digitIndex) {
          return i + 1;
        }
      }
    }
    return formattedValue.length;
  }

  function attachMoneyFormatter(input) {
    const handler = () => {
      const prev = input.value;
      const caret = input.selectionStart ?? prev.length;
      const digitsLeft = countDigitsLeftOfCaret(prev, caret);
      const digits = digitsOnly(prev);
      const formatted = formatWithCommas(digits);
      input.value = formatted;
      const newCaret = caretPosForDigitIndex(formatted, digitsLeft);
      try {
        input.setSelectionRange(newCaret, newCaret);
      } catch (_) {}
    };

    input.addEventListener("input", handler);
    input.addEventListener("blur", handler);

    handler();
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("input[data-money]").forEach((el) => {
      if (el instanceof HTMLInputElement) {
        attachMoneyFormatter(el);
      }
    });

    document.querySelectorAll("[data-countdown-seconds]").forEach((el) => {
      const raw = el.getAttribute("data-countdown-seconds") || "";
      let seconds = parseInt(raw, 10);
      if (!Number.isFinite(seconds) || seconds <= 0) return;

      const valueEl = el.querySelector("[data-countdown-value]") || el;

      const tick = () => {
        if (seconds <= 0) {
          return;
        }
        seconds -= 1;
        valueEl.textContent = String(seconds);
      };

      const timer = window.setInterval(() => {
        tick();
        if (seconds <= 0) {
          window.clearInterval(timer);
        }
      }, 1000);
    });
  });
})();
