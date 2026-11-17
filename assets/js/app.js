/**
 * SwiftDrive Rentals - Client-Side Enhancements & Live Quote Calculator
 * Provides client-side date validation (matching PHP server rules), live LKR weekly/daily
 * rate estimation, password visibility toggles, and vehicle gallery switcher.
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Live Rental Quote Estimator on Vehicle Details & Booking Pages
    const quoteForm = document.querySelector('[data-quote-calculator]');
    if (quoteForm) {
        const pickupInput = quoteForm.querySelector('[name="pickup_datetime"]');
        const returnInput = quoteForm.querySelector('[name="return_datetime"]');
        const pickupLocSelect = quoteForm.querySelector('[name="pickup_location_id"]');
        const returnLocSelect = quoteForm.querySelector('[name="return_location_id"]');

        const dailyRate = parseFloat(quoteForm.dataset.dailyRate || '0');
        const weeklyRate = parseFloat(quoteForm.dataset.weeklyRate || '0');
        const depositRate = parseFloat(quoteForm.dataset.depositRate || '0.25');

        const outDuration = document.getElementById('quote-duration-text');
        const outBase = document.getElementById('quote-base-lkr');
        const outSurcharge = document.getElementById('quote-surcharge-lkr');
        const outSavings = document.getElementById('quote-savings-row');
        const outSavingsAmt = document.getElementById('quote-savings-lkr');
        const outTotal = document.getElementById('quote-total-lkr');
        const outDeposit = document.getElementById('quote-deposit-lkr');
        const outBalance = document.getElementById('quote-balance-lkr');
        const dateAlert = document.getElementById('quote-date-error');
        const submitBtn = quoteForm.querySelector('button[type="submit"]');

        function formatLKR(num) {
            return 'LKR ' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalculate() {
            if (!pickupInput || !returnInput || !pickupInput.value || !returnInput.value) {
                return;
            }
            const pDate = new Date(pickupInput.value);
            const rDate = new Date(returnInput.value);
            const nowWithGrace = new Date(Date.now() - 10 * 60 * 1000);
            const diffSeconds = (rDate.getTime() - pDate.getTime()) / 1000;

            if (isNaN(pDate.getTime()) || isNaN(rDate.getTime())) {
                if (dateAlert) {
                    dateAlert.textContent = 'Please enter valid pickup and return dates.';
                    dateAlert.classList.remove('d-none');
                }
                if (submitBtn) submitBtn.disabled = true;
                return;
            }

            if (pDate < nowWithGrace) {
                if (dateAlert) {
                    dateAlert.textContent = 'Pickup date and time cannot be in the past.';
                    dateAlert.classList.remove('d-none');
                }
                if (submitBtn) submitBtn.disabled = true;
                return;
            }

            if (diffSeconds < 3600) {
                if (dateAlert) {
                    dateAlert.textContent = 'Return date & time must be at least 1 hour after pickup.';
                    dateAlert.classList.remove('d-none');
                }
                if (submitBtn) submitBtn.disabled = true;
                return;
            }

            if (diffSeconds > 90 * 86400) {
                if (dateAlert) {
                    dateAlert.textContent = 'Online self-service bookings are capped at 90 days. Please contact our Negombo desk for longer leases.';
                    dateAlert.classList.remove('d-none');
                }
                if (submitBtn) submitBtn.disabled = true;
                return;
            }

            if (dateAlert) {
                dateAlert.classList.add('d-none');
            }
            if (submitBtn) submitBtn.disabled = false;

            const totalDays = Math.max(1, Math.ceil(diffSeconds / 86400));
            const fullWeeks = Math.floor(totalDays / 7);
            const remDays = totalDays % 7;

            let remCost = remDays * dailyRate;
            if (weeklyRate > 0 && remCost > weeklyRate) {
                remCost = weeklyRate;
            }

            const baseRental = (fullWeeks * weeklyRate) + remCost;
            const unbundled = totalDays * dailyRate;
            const savings = Math.max(0, unbundled - baseRental);

            let surcharge = 0;
            if (pickupLocSelect && pickupLocSelect.selectedOptions[0]) {
                surcharge += parseFloat(pickupLocSelect.selectedOptions[0].dataset.surcharge || '0');
            }
            if (returnLocSelect && returnLocSelect.selectedOptions[0]) {
                surcharge += parseFloat(returnLocSelect.selectedOptions[0].dataset.surcharge || '0');
            }

            const total = baseRental + surcharge;
            const deposit = Math.round(total * depositRate * 100) / 100;
            const balance = Math.max(0, total - deposit);

            if (outDuration) {
                let label = totalDays + (totalDays === 1 ? ' Day' : ' Days');
                if (fullWeeks > 0) {
                    label += ' (' + fullWeeks + ' wk' + (remDays > 0 ? ' + ' + remDays + ' d' : '') + ')';
                }
                outDuration.textContent = label;
            }
            if (outBase) outBase.textContent = formatLKR(baseRental);
            if (outSurcharge) outSurcharge.textContent = formatLKR(surcharge);
            if (outSavings && outSavingsAmt) {
                if (savings > 0) {
                    outSavingsAmt.textContent = '- ' + formatLKR(savings);
                    outSavings.classList.remove('d-none');
                } else {
                    outSavings.classList.add('d-none');
                }
            }
            if (outTotal) outTotal.textContent = formatLKR(total);
            if (outDeposit) outDeposit.textContent = formatLKR(deposit);
            if (outBalance) outBalance.textContent = formatLKR(balance);
        }

        [pickupInput, returnInput, pickupLocSelect, returnLocSelect].forEach(function (el) {
            if (el) {
                el.addEventListener('change', recalculate);
                el.addEventListener('input', recalculate);
            }
        });

        recalculate();
    }

    // 2. Accessible Password Visibility Toggles
    const toggleButtons = document.querySelectorAll('[data-toggle-password]');
    toggleButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = btn.getAttribute('data-toggle-password');
            const input = document.getElementById(targetId);
            if (!input) return;

            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                btn.setAttribute('aria-label', 'Hide password');
                btn.setAttribute('aria-pressed', 'true');
                if (icon) {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
            } else {
                input.type = 'password';
                btn.setAttribute('aria-label', 'Show password');
                btn.setAttribute('aria-pressed', 'false');
                if (icon) {
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            }
        });
    });

    // 4. Vehicle Details Gallery Thumbnail Switcher
    const mainVehicleImg = document.getElementById('vehicle-main-image');
    const thumbButtons = document.querySelectorAll('[data-gallery-src]');
    if (mainVehicleImg && thumbButtons.length > 0) {
        thumbButtons.forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                const newSrc = thumb.getAttribute('data-gallery-src');
                const newAlt = thumb.getAttribute('data-gallery-alt');
                if (newSrc) {
                    mainVehicleImg.src = newSrc;
                }
                if (newAlt) {
                    mainVehicleImg.alt = newAlt;
                }
                thumbButtons.forEach(function (t) { t.classList.remove('active'); });
                thumb.classList.add('active');
            });
        });
    }
});

