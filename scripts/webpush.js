/*
 * Web Push (issue #162): subscribe this browser / installed PWA to renewal
 * reminders and hand the PushSubscription to the server, or unsubscribe again.
 *
 * The service worker (service-worker.js) receives the push and shows the
 * notification; this only manages the subscription and its storage.
 */

function wallosWebPushSupported() {
    return ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
}

// translate() has no cross-language fallback in the browser (it answers
// "[Translation Missing]" for an absent key), so a locale that has not
// translated these few status strings still reads sensibly in English.
function wallosWebPushText(key, fallback) {
    if (typeof translate === 'function') {
        const value = translate(key);
        if (value && value !== '[Translation Missing]') {
            return value;
        }
    }
    return fallback;
}

function wallosWebPushUrlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    const output = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        output[i] = rawData.charCodeAt(i);
    }
    return output;
}

function wallosWebPushArrayBufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function wallosWebPushSetStatus(message) {
    const status = document.getElementById('webPushStatus');
    if (status) {
        status.textContent = message;
    }
}

function wallosWebPushReflectState(subscribed) {
    const enableButton = document.getElementById('webPushEnable');
    const disableButton = document.getElementById('webPushDisable');
    if (enableButton) {
        enableButton.style.display = subscribed ? 'none' : '';
    }
    if (disableButton) {
        disableButton.style.display = subscribed ? '' : 'none';
    }
}

function wallosWebPushRegister() {
    return navigator.serviceWorker.register('service-worker.js').then(function () {
        return navigator.serviceWorker.ready;
    });
}

function wallosWebPushPost(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.csrfToken
        },
        body: JSON.stringify(body)
    }).then(function (response) {
        return response.json();
    });
}

async function enableWebPush() {
    if (!wallosWebPushSupported()) {
        wallosWebPushSetStatus(wallosWebPushText('web_push_unsupported', 'Web Push is not supported in this browser.'));
        return;
    }

    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            wallosWebPushSetStatus(wallosWebPushText('web_push_permission_denied', 'Notification permission was denied.'));
            return;
        }

        const registration = await wallosWebPushRegister();

        const keyResponse = await fetch('endpoints/notifications/getwebpushkey.php');
        const keyData = await keyResponse.json();
        if (!keyData.success || !keyData.publicKey) {
            wallosWebPushSetStatus(wallosWebPushText('web_push_not_configured', 'Web Push is not configured on the server.'));
            return;
        }

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: wallosWebPushUrlBase64ToUint8Array(keyData.publicKey)
            });
        }

        const raw = subscription.toJSON();
        const payload = {
            action: 'subscribe',
            endpoint: subscription.endpoint,
            keys: {
                p256dh: (raw.keys && raw.keys.p256dh) || wallosWebPushArrayBufferToBase64Url(subscription.getKey('p256dh')),
                auth: (raw.keys && raw.keys.auth) || wallosWebPushArrayBufferToBase64Url(subscription.getKey('auth'))
            }
        };

        const result = await wallosWebPushPost('endpoints/notifications/savewebpushnotifications.php', payload);
        if (result.success) {
            wallosWebPushSetStatus(wallosWebPushText('web_push_enabled', 'Web Push enabled on this device.'));
            wallosWebPushReflectState(true);
        } else {
            wallosWebPushSetStatus(result.message || wallosWebPushText('error', 'Something went wrong.'));
        }
    } catch (error) {
        wallosWebPushSetStatus(wallosWebPushText('error', 'Something went wrong.'));
    }
}

async function disableWebPush() {
    if (!wallosWebPushSupported()) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();

        if (subscription) {
            await wallosWebPushPost('endpoints/notifications/savewebpushnotifications.php', {
                action: 'unsubscribe',
                endpoint: subscription.endpoint
            });
            await subscription.unsubscribe();
        }

        wallosWebPushSetStatus(wallosWebPushText('web_push_disabled', 'Web Push disabled on this device.'));
        wallosWebPushReflectState(false);
    } catch (error) {
        wallosWebPushSetStatus(wallosWebPushText('error', 'Something went wrong.'));
    }
}

// Reflect the current subscription state when the settings page loads.
document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('webPushStatus')) {
        return;
    }

    if (!wallosWebPushSupported()) {
        wallosWebPushSetStatus(wallosWebPushText('web_push_unsupported', 'Web Push is not supported in this browser.'));
        wallosWebPushReflectState(false);
        return;
    }

    navigator.serviceWorker.ready.then(function (registration) {
        return registration.pushManager.getSubscription();
    }).then(function (subscription) {
        wallosWebPushReflectState(!!subscription);
    }).catch(function () {
        wallosWebPushReflectState(false);
    });
});
