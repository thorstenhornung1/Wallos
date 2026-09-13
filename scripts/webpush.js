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

// The handle the server uses to name a device: the first 16 hex characters of
// the SHA-256 of the endpoint. Computed here so the list can mark which row is
// the browser you are looking at, without the page having to send its endpoint
// anywhere. crypto.subtle needs a secure context, which is the same thing Push
// itself needs, so it is available wherever this code runs at all.
async function wallosWebPushHandle(endpoint) {
    const bytes = new TextEncoder().encode(endpoint);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest))
        .map(function (b) { return b.toString(16).padStart(2, '0'); })
        .join('')
        .slice(0, 16);
}

function wallosWebPushDeviceName(device) {
    const browser = device.browser || '';
    const platform = device.platform || '';

    if (browser && platform) {
        return browser + ' \u2014 ' + platform;
    }
    if (browser || platform) {
        return browser || platform;
    }
    return wallosWebPushText('web_push_unknown_device', 'Unknown device');
}

// Renders the account's subscribed devices. Every value placed in the page goes
// in as text, never as markup: the browser and platform names come from a fixed
// server-side list, but the date is formatted here and the row is built from
// data that arrived over the network, so there is no reason to build it any
// other way.
function wallosWebPushRenderDevices(devices, currentHandle) {
    const list = document.getElementById('webPushDevices');
    if (!list) {
        return;
    }

    list.textContent = '';

    if (!devices || devices.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'webpush-device-empty';
        empty.textContent = wallosWebPushText('web_push_no_devices', 'No devices are subscribed.');
        list.appendChild(empty);
        return;
    }

    devices.forEach(function (device) {
        const row = document.createElement('div');
        row.className = 'webpush-device';

        const label = document.createElement('span');
        label.className = 'webpush-device-name';
        label.textContent = wallosWebPushDeviceName(device);
        row.appendChild(label);

        if (device.handle === currentHandle) {
            const here = document.createElement('span');
            here.className = 'webpush-device-current';
            here.textContent = wallosWebPushText('web_push_this_device', 'This device');
            row.appendChild(here);
        }

        if (device.created_at) {
            const added = document.createElement('span');
            added.className = 'webpush-device-added';
            added.textContent = new Date(device.created_at * 1000).toLocaleDateString();
            row.appendChild(added);
        }

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'webpush-device-remove';
        remove.textContent = wallosWebPushText('delete', 'Delete');
        remove.addEventListener('click', function () {
            wallosWebPushRemoveDevice(device.handle);
        });
        row.appendChild(remove);

        list.appendChild(row);
    });
}

async function wallosWebPushCurrentHandle() {
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        return subscription ? await wallosWebPushHandle(subscription.endpoint) : '';
    } catch (error) {
        return '';
    }
}

async function wallosWebPushLoadDevices() {
    if (!document.getElementById('webPushDevices')) {
        return;
    }

    try {
        const response = await fetch('endpoints/notifications/getwebpushdevices.php');
        const data = await response.json();
        if (data.success) {
            wallosWebPushRenderDevices(data.devices, await wallosWebPushCurrentHandle());
        }
    } catch (error) {
        // A list that cannot be loaded is left as it is; the enable/disable
        // buttons do not depend on it.
    }
}

async function wallosWebPushRemoveDevice(handle) {
    try {
        const currentHandle = await wallosWebPushCurrentHandle();
        const result = await wallosWebPushPost('endpoints/notifications/savewebpushnotifications.php', {
            action: 'remove_device',
            handle: handle
        });

        if (!result.success) {
            wallosWebPushSetStatus(result.message || wallosWebPushText('error', 'Something went wrong.'));
            return;
        }

        // Removing the row for this browser leaves it holding a PushSubscription
        // the server no longer knows, so the browser's own subscription goes
        // too. Otherwise the page would offer "disable on this device" for
        // something already gone, and re-enabling would store the same endpoint
        // again as if nothing had happened.
        if (handle === currentHandle) {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                if (subscription) {
                    await subscription.unsubscribe();
                }
            } catch (error) {
                // The row is gone either way; the button state below is what
                // the page shows.
            }
            wallosWebPushReflectState(false);
        }

        wallosWebPushRenderDevices(result.devices, handle === currentHandle ? '' : currentHandle);
    } catch (error) {
        wallosWebPushSetStatus(wallosWebPushText('error', 'Something went wrong.'));
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
            wallosWebPushRenderDevices(result.devices, await wallosWebPushHandle(subscription.endpoint));
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
        wallosWebPushLoadDevices();
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

    wallosWebPushLoadDevices();
});
