// The one-off default-name migration page (localize.php). One button, both
// halves: the endpoint already accepts a currencies and a payment_methods
// bucket in the same request, which is what lets this page keep the promise the
// dashboard banner makes ("currency and payment method names") in a single
// click. Deleted together with localize.php when the migration has run its
// course.

function applyLocalizeAll() {
  const container = document.getElementById('localize-defaults');
  if (!container) return;

  const ids = (selector) =>
    Array.from(container.querySelectorAll(selector + ':checked')).map((checkbox) => checkbox.value);

  const currencies = ids('.localize-currency-checkbox');
  const paymentMethods = ids('.localize-payment-checkbox');

  const body = new URLSearchParams();
  body.append('currencies', currencies.join(','));
  body.append('payment_methods', paymentMethods.join(','));

  fetch(container.dataset.endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': window.csrfToken,
    },
    body: body,
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        // Nothing is left to rename, so the reload leaves this page for good:
        // localize.php redirects an account without candidates to the dashboard.
        setTimeout(() => location.reload(), 800);
      } else {
        showErrorMessage(data.message || translate('unknown_error'));
      }
    })
    .catch((error) => {
      console.error(error);
      showErrorMessage(translate('unknown_error'));
    });
}
