/**
 * Lümia Tools - Security admin JS
 * Visibilité conditionnelle des sous-options + conversion dynamique secondes → minutes.
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    initSecurityToggles();
    initSecondsToMinutes();
  });

  /* ================================================================
   * VISIBILITÉ CONDITIONNELLE
   * Toggles [data-security-toggle] → affiche/masque [data-depends-on]
   * ================================================================ */

  function initSecurityToggles() {
    var toggles = document.querySelectorAll("[data-security-toggle]");

    toggles.forEach(function (toggle) {
      toggle.addEventListener("change", function () {
        var key = this.getAttribute("data-security-toggle");
        var dependents = document.querySelectorAll(
          '[data-depends-on="' + key + '"]',
        );
        dependents.forEach(function (el) {
          el.style.display = toggle.checked ? "" : "none";
        });
      });
    });
  }

  /* ================================================================
   * CONVERSION SECONDES → MINUTES EN TEMPS RÉEL
   * Inputs [data-seconds-field] mettent à jour [data-minutes-display]
   * ================================================================ */

  function initSecondsToMinutes() {
    var fields = document.querySelectorAll("[data-seconds-field]");

    fields.forEach(function (input) {
      var targetId = input.getAttribute("data-seconds-field");
      var display = document.getElementById(targetId);
      if (!display) return;

      function update() {
        var seconds = parseInt(input.value, 10);
        if (isNaN(seconds) || seconds <= 0) {
          display.textContent = "";
          return;
        }
        if (seconds < 60) {
          display.textContent = seconds + " s";
        } else {
          var mins = Math.round(seconds / 60);
          display.textContent = mins + " min";
        }
      }

      input.addEventListener("input", update);
      update();
    });
  }
})();
