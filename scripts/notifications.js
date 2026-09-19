function openNotificationsSettings(type) {
    // Get all .account-notification-section-settings elements
    var sections = document.querySelectorAll('.account-notification-section-settings');
    var targetSection = document.querySelector(`.account-notification-section-settings[data-type="${type}"]`);
    
    // Remove the is-open class from all elements
    sections.forEach(function(section) {
      if (section !== targetSection) {
        section.classList.remove('is-open');
      }
    });
  
    // Add the is-open class to the element with data-type=type
  
    if (targetSection && !targetSection.classList.contains('is-open')) {
      targetSection.classList.add('is-open');
    } else {
      targetSection.classList.remove('is-open');
    }
}

function makeFetchCall(url, data, button) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            "X-CSRF-Token": window.csrfToken,
        },
        body: JSON.stringify(data),
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showSuccessMessage(data.message);
        } else {
            showErrorMessage(data.message);
        }
        button.disabled = false;
    })
    .catch((error) => {
        showErrorMessage(error);
        button.disabled = false;
    });

}

function saveNotificationsButton() {
    const button = document.getElementById("saveNotifications");
    button.disabled = true;
    const days = document.querySelector('#days').value;
    const periodSummaryAtPeriodStart = document.getElementById("period_summary_at_period_start").checked ? 1 : 0;

    const url = 'endpoints/notifications/savenotificationsettings.php';
    const data = {
        days: days,
        period_summary_at_period_start: periodSummaryAtPeriodStart,
    };

    makeFetchCall(url, data, button);
}

function getSmtpMode() {
    const selected = document.querySelector('input[name="smtpmode"]:checked');
    return selected ? selected.value : "custom";
}

// The instance transport is resolved server side, so nothing but the mode is
// sent when it is selected.
function collectSmtpFormData() {
    const smtpMode = getSmtpMode();

    if (smtpMode === "instance") {
      return { smtpmode: smtpMode };
    }

    return {
      smtpmode: smtpMode,
      smtpaddress: document.getElementById("smtpaddress").value,
      smtpport: document.getElementById("smtpport").value,
      encryption: document.querySelector('input[name="encryption"]:checked').value,
      smtpusername: document.getElementById("smtpusername").value,
      smtppassword: document.getElementById("smtppassword").value,
      fromemail: document.getElementById("fromemail").value
    };
}

function toggleSmtpMode() {
    const usesInstance = getSmtpMode() === "instance";
    const instanceInfo = document.getElementById("instanceSmtpInfo");
    const customFields = document.getElementById("customSmtpFields");

    if (instanceInfo) {
      instanceInfo.style.display = usesInstance ? "" : "none";
    }
    if (customFields) {
      customFields.style.display = usesInstance ? "none" : "";
    }
}

function saveNotificationsEmailButton() {
    const button = document.getElementById("saveNotificationsEmail");
    button.disabled = true;

    const data = collectSmtpFormData();
    data.enabled = document.getElementById("emailenabled").checked ? 1 : 0;
    data.otheremails = document.getElementById("otheremails").value;

    makeFetchCall('endpoints/notifications/saveemailnotifications.php', data, button);
}

function testNotificationEmailButton()  {
    const button = document.getElementById("testNotificationsEmail");
    button.disabled = true;

    const data = collectSmtpFormData();
    // Lets the endpoint point out that notifications are not enabled yet; the
    // admin transport test has no user notifications to speak of.
    data.context = "user";

    makeFetchCall('endpoints/notifications/testemailnotifications.php', data, button);
}

function saveNotificationsWebhookButton() {
    const button = document.getElementById("saveNotificationsWebhook");
    button.disabled = true;
  
    const enabled = document.getElementById("webhookenabled").checked ? 1 : 0;
    const webhook_url = document.getElementById("webhookurl").value;
    const headers = document.getElementById("webhookcustomheaders").value;
    const payload = document.getElementById("webhookpayload").value;
    const cancelation_payload = document.getElementById("webhookcancelationpayload").value;
    const ignore_ssl = document.getElementById("webhookignoressl").checked ? 1 : 0;
  
    const data = {
      enabled: enabled,
      webhook_url: webhook_url,
      headers: headers,
      payload: payload,
      cancelation_payload: cancelation_payload,
      ignore_ssl: ignore_ssl
    };

    makeFetchCall('endpoints/notifications/savewebhooknotifications.php', data, button);
}

function testNotificationsWebhookButton() {
    const button = document.getElementById("testNotificationsWebhook");
    button.disabled = true;
  
    const enabled = document.getElementById("webhookenabled").checked ? 1 : 0;
    const requestmethod = document.getElementById("webhookrequestmethod").value;
    const url = document.getElementById("webhookurl").value;
    const customheaders = document.getElementById("webhookcustomheaders").value;
    const payload = document.getElementById("webhookpayload").value;
    const cancelation_payload = document.getElementById("webhookcancelationpayload").value;
    const ignore_ssl = document.getElementById("webhookignoressl").checked ? 1 : 0;
  
    const data = {
      enabled: enabled,
      requestmethod: requestmethod,
      url: url,
      customheaders: customheaders,
      payload: payload,
      cancelation_payload: cancelation_payload,
      ignore_ssl: ignore_ssl
    };

    makeFetchCall('endpoints/notifications/testwebhooknotifications.php', data, button);
}

function getTelegramMode() {
    const selected = document.querySelector('input[name="telegrammode"]:checked');
    return selected ? selected.value : "custom";
}

// The instance bot token is resolved server side, so nothing but the mode and
// the personal chat id is sent when the instance bot is selected.
function toggleTelegramMode() {
    const usesInstance = getTelegramMode() === "instance";
    const instanceInfo = document.getElementById("instanceTelegramInfo");
    const customFields = document.getElementById("customTelegramFields");

    if (instanceInfo) {
      instanceInfo.style.display = usesInstance ? "" : "none";
    }
    if (customFields) {
      customFields.style.display = usesInstance ? "none" : "";
    }
}

function saveNotificationsTelegramButton() {
    const button = document.getElementById("saveNotificationsTelegram");
    button.disabled = true;

    const mode = getTelegramMode();
    const data = {
      enabled: document.getElementById("telegramenabled").checked ? 1 : 0,
      chat_id: document.getElementById("telegramchatid").value,
      mode: mode
    };

    if (mode === "custom") {
      data.bot_token = document.getElementById("telegrambottoken").value;
    }

    makeFetchCall('endpoints/notifications/savetelegramnotifications.php', data, button);
}

function testNotificationsTelegramButton() {
    const button = document.getElementById("testNotificationsTelegram");
    button.disabled = true;

    const mode = getTelegramMode();
    const data = {
      enabled: document.getElementById("telegramenabled").checked ? 1 : 0,
      chatid: document.getElementById("telegramchatid").value,
      mode: mode
    };

    if (mode === "custom") {
      data.bottoken = document.getElementById("telegrambottoken").value;
    }

    makeFetchCall('endpoints/notifications/testtelegramnotifications.php', data, button);
}

function testNotificationsPushPlusButton() {
    const button = document.getElementById("testNotificationsPushPlus");
    button.disabled = true;
  
    const enabled = document.getElementById("pushplusenabled").checked ? 1 : 0;
    const token = document.getElementById("pushplustoken").value;
  
    const data = {
      enabled: enabled,
      token: token
    };

    makeFetchCall('endpoints/notifications/testpushplusnotifications.php', data, button);
}

function saveNotificationsPushPlusButton() {
    const button = document.getElementById("saveNotificationsPushPlus");
    button.disabled = true;
  
    const enabled = document.getElementById("pushplusenabled").checked ? 1 : 0;
    const token = document.getElementById("pushplustoken").value;
  
    const data = {
      enabled: enabled,
      token: token
    };

    makeFetchCall('endpoints/notifications/savepushplusnotifications.php', data, button);
}

function testNotificationsMattermostButton() {
    const button = document.getElementById("testNotificationsMattermost");
    button.disabled = true;
  
    const enabled = document.getElementById("mattermostenabled").checked ? 1 : 0;
    const webhook_url = document.getElementById("mattermostwebhookurl").value;
    const bot_username = document.getElementById("mattermostbotusername").value;
    const bot_icon_emoji = document.getElementById("mattermostboticonemoji").value;
  
    const data = {
      enabled: enabled,
      webhook_url: webhook_url,
      bot_username: bot_username,
      bot_icon_emoji: bot_icon_emoji
    };

    makeFetchCall('endpoints/notifications/testmattermostnotifications.php', data, button);
}

function saveNotificationsMattermostButton() {
    const button = document.getElementById("saveNotificationsMattermost");
    button.disabled = true;
  
    const enabled = document.getElementById("mattermostenabled").checked ? 1 : 0;
    const webhook_url = document.getElementById("mattermostwebhookurl").value;
    const bot_username = document.getElementById("mattermostbotusername").value;
    const bot_icon_emoji = document.getElementById("mattermostboticonemoji").value;
  
    const data = {
      enabled: enabled,
      webhook_url: webhook_url,
      bot_username: bot_username,
      bot_icon_emoji: bot_icon_emoji
    };

    makeFetchCall('endpoints/notifications/savemattermostnotifications.php', data, button);
}

function getGotifyMode() {
    const selected = document.querySelector('input[name="gotifymode"]:checked');
    return selected ? selected.value : "custom";
}

// The instance server host is resolved server side, so the URL is not sent when
// it is selected. The application token always goes: it stays with the user.
function toggleGotifyMode() {
    const usesInstance = getGotifyMode() === "instance";
    const instanceInfo = document.getElementById("instanceGotifyInfo");
    const customFields = document.getElementById("customGotifyFields");

    if (instanceInfo) {
      instanceInfo.style.display = usesInstance ? "" : "none";
    }
    if (customFields) {
      customFields.style.display = usesInstance ? "none" : "";
    }
}

function saveNotificationsGotifyButton() {
    const button = document.getElementById("saveNotificationsGotify");
    button.disabled = true;

    const mode = getGotifyMode();
    const data = {
      enabled: document.getElementById("gotifyenabled").checked ? 1 : 0,
      token: document.getElementById("gotifytoken").value,
      ignore_ssl: document.getElementById("gotifyignoressl").checked ? 1 : 0,
      mode: mode
    };

    if (mode === "custom") {
      data.gotify_url = document.getElementById("gotifyurl").value;
    }

    makeFetchCall('endpoints/notifications/savegotifynotifications.php', data, button);
}


function testNotificationsGotifyButton() {
    const button = document.getElementById("testNotificationsGotify");
    button.disabled = true;

    const mode = getGotifyMode();
    const data = {
      enabled: document.getElementById("gotifyenabled").checked ? 1 : 0,
      token: document.getElementById("gotifytoken").value,
      ignore_ssl: document.getElementById("gotifyignoressl").checked ? 1 : 0,
      mode: mode
    };

    if (mode === "custom") {
      data.gotify_url = document.getElementById("gotifyurl").value;
    }

    makeFetchCall('endpoints/notifications/testgotifynotifications.php', data, button);
}

function getPushoverMode() {
  const selected = document.querySelector('input[name="pushovermode"]:checked');
  return selected ? selected.value : "custom";
}

// The instance application token is resolved server side, so nothing but the
// mode and the personal user key is sent when the instance application is used.
function togglePushoverMode() {
  const usesInstance = getPushoverMode() === "instance";
  const instanceInfo = document.getElementById("instancePushoverInfo");
  const customFields = document.getElementById("customPushoverFields");

  if (instanceInfo) {
    instanceInfo.style.display = usesInstance ? "" : "none";
  }
  if (customFields) {
    customFields.style.display = usesInstance ? "none" : "";
  }
}

function saveNotificationsPushoverButton() {
  const button = document.getElementById("saveNotificationsPushover");
  button.disabled = true;

  const mode = getPushoverMode();
  const data = {
    enabled: document.getElementById("pushoverenabled").checked ? 1 : 0,
    user_key: document.getElementById("pushoveruserkey").value,
    mode: mode
  };

  if (mode === "custom") {
    data.token = document.getElementById("pushovertoken").value;
  }

  makeFetchCall('endpoints/notifications/savepushovernotifications.php', data, button);
}

function testNotificationsPushoverButton() {
  const button = document.getElementById("testNotificationsPushover");
  button.disabled = true;

  const mode = getPushoverMode();
  const data = {
    enabled: document.getElementById("pushoverenabled").checked ? 1 : 0,
    user_key: document.getElementById("pushoveruserkey").value,
    mode: mode
  };

  if (mode === "custom") {
    data.token = document.getElementById("pushovertoken").value;
  }

  makeFetchCall('endpoints/notifications/testpushovernotifications.php', data, button);
}

function saveNotificationsDiscordButton() {
  const button = document.getElementById("saveNotificationsDiscord");
  button.disabled = true;

  const enabled = document.getElementById("discordenabled").checked ? 1 : 0;
  const url = document.getElementById("discordurl").value;
  const bot_username = document.getElementById("discordbotusername").value;
  const bot_avatar = document.getElementById("discordbotavatar").value;

  const data = {
    enabled: enabled,
    url: url,
    bot_username: bot_username,
    bot_avatar: bot_avatar
  };

  makeFetchCall('endpoints/notifications/savediscordnotifications.php', data, button);
}

function testNotificationsDiscordButton() {
  const button = document.getElementById("testNotificationsDiscord");
  button.disabled = true;

  const enabled = document.getElementById("discordenabled").checked ? 1 : 0;
  const url = document.getElementById("discordurl").value;
  const bot_username = document.getElementById("discordbotusername").value;
  const bot_avatar = document.getElementById("discordbotavatar").value;

  const data = {
    enabled: enabled,
    url: url,
    bot_username: bot_username,
    bot_avatar: bot_avatar
  };

  makeFetchCall('endpoints/notifications/testdiscordnotifications.php', data, button);
}

function getNtfyMode() {
  const selected = document.querySelector('input[name="ntfymode"]:checked');
  return selected ? selected.value : "custom";
}

// The instance server is resolved server side, so the host is not sent when it
// is selected. The topic and the optional header override always go.
function toggleNtfyMode() {
  const usesInstance = getNtfyMode() === "instance";
  const instanceInfo = document.getElementById("instanceNtfyInfo");
  const customFields = document.getElementById("customNtfyFields");

  if (instanceInfo) {
    instanceInfo.style.display = usesInstance ? "" : "none";
  }
  if (customFields) {
    customFields.style.display = usesInstance ? "none" : "";
  }
}

function testNotificationsNtfyButton() {
  const button = document.getElementById("testNotificationsNtfy");
  button.disabled = true;

  const mode = getNtfyMode();
  const data = {
    topic: document.getElementById("ntfytopic").value,
    headers: document.getElementById("ntfyheaders").value,
    ignore_ssl: document.getElementById("ntfyignoressl").checked ? 1 : 0,
    mode: mode
  };

  if (mode === "custom") {
    data.host = document.getElementById("ntfyhost").value;
  }

  makeFetchCall('endpoints/notifications/testntfynotifications.php', data, button);
}

function saveNotificationsNtfyButton() {
  const button = document.getElementById("saveNotificationsNtfy");
  button.disabled = true;

  const mode = getNtfyMode();
  const data = {
    enabled: document.getElementById("ntfyenabled").checked ? 1 : 0,
    topic: document.getElementById("ntfytopic").value,
    headers: document.getElementById("ntfyheaders").value,
    ignore_ssl: document.getElementById("ntfyignoressl").checked ? 1 : 0,
    mode: mode
  };

  if (mode === "custom") {
    data.host = document.getElementById("ntfyhost").value;
  }

  makeFetchCall('endpoints/notifications/saventfynotifications.php', data, button);
}

function testNotificationsServerchanButton() {
  const button = document.getElementById("testNotificationsServerchan");
  button.disabled = true;

  const enabled = document.getElementById("serverchanenabled").checked ? 1 : 0;
  const sendkey = document.getElementById("serverchansendkey").value;

  const data = {
    enabled: enabled,
    sendkey: sendkey
  };

  makeFetchCall('endpoints/notifications/testserverchannotifications.php', data, button);
}

function saveNotificationsServerchanButton() {
  const button = document.getElementById("saveNotificationsServerchan");
  button.disabled = true;

  const enabled = document.getElementById("serverchanenabled").checked ? 1 : 0;
  const sendkey = document.getElementById("serverchansendkey").value;

  const data = {
    enabled: enabled,
    sendkey: sendkey
  };

  makeFetchCall('endpoints/notifications/saveserverchannotifications.php', data, button);
}
// Push notifications ---------------------------------------------------
//
// Unlike every other channel above, there is no host, token or key for the
// user to type in: the "configuration" is the browser's own Push
// subscription, created by subscribePushButtonClick() and handed straight to
// the server. The enabled checkbox is the only thing
// saveNotificationsPushButton() ever saves on its own.
//
// A device is named by its handle, never by its endpoint. The endpoint is the
// address that receives this account's notifications, and the page has no use
// for it beyond recognising which row is the browser looking at it - which the
// same hash answers, computed here.

// translate() has no cross-language fallback in the browser: it answers with
// the key itself when a language file has not been updated yet, and a button
// labelled "web_push_this_device" is worse than one labelled in English. Every
// string this section adds therefore carries the English it falls back to.
function pushText(key, english) {
  const value = typeof translate === 'function' ? translate(key) : key;

  return (!value || value === key) ? english : value;
}

// pushManager.subscribe() takes the VAPID public key as a Uint8Array, not the
// base64url string the server hands over; this is the standard conversion.
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }

  return outputArray;
}

// The same handle the server computes: the first sixteen hex characters of
// the SHA-256 of the endpoint.
async function pushDeviceHandle(endpoint) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));

  return Array.from(new Uint8Array(digest))
    .map(byte => byte.toString(16).padStart(2, '0'))
    .join('')
    .slice(0, 16);
}

// Builds (or replaces) one device's row from what savepushsubscription.php
// handed back, without touching anything else on the page - in particular
// without a reload, which would collapse every notification section again.
//
// Every value goes in through textContent and addEventListener rather than
// innerHTML, even though the label is now words the server chose rather than
// the user agent the browser sent.
function renderPushDeviceRow(subscription) {
  const list = document.getElementById("pushDevicesList");
  const existingRow = list.querySelector(`.push-device-row[data-handle="${subscription.handle}"]`);

  const row = existingRow || document.createElement("div");
  row.className = "push-device-row";
  row.setAttribute("data-handle", subscription.handle);
  row.innerHTML = "";

  const name = document.createElement("span");
  name.className = "push-device-name";
  const label = [subscription.browser, subscription.platform].filter(Boolean).join(' ');
  name.textContent = label !== '' ? label : pushText('unknown_device', 'Unknown device');
  row.appendChild(name);

  const deleteButton = document.createElement("button");
  deleteButton.type = "button";
  deleteButton.className = "secondary-button thin";
  deleteButton.textContent = pushText('delete', 'Delete');
  deleteButton.addEventListener('click', function () {
    removePushSubscriptionButton(subscription.handle, row);
  });
  row.appendChild(deleteButton);

  if (!existingRow) {
    const noDevices = document.getElementById("noPushDevices");
    if (noDevices) {
      noDevices.remove();
    }
    list.appendChild(row);
  }
}

function subscribePushButtonClick() {
  const button = document.getElementById("subscribePushButton");

  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    showErrorMessage(pushText('web_push_unsupported', 'Web Push is not supported in this browser.'));
    return;
  }

  button.disabled = true;

  Notification.requestPermission().then(function (permission) {
    if (permission !== 'granted') {
      showErrorMessage(pushText('web_push_permission_denied', 'Notification permission was denied.'));
      button.disabled = false;
      return;
    }

    navigator.serviceWorker.ready.then(function (registration) {
      return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(window.vapidPublicKey),
      });
    }).then(function (subscription) {
      return fetch('endpoints/notifications/savepushsubscription.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.csrfToken,
        },
        body: JSON.stringify(subscription.toJSON()),
      });
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      if (data.success) {
        renderPushDeviceRow(data.subscription);
        markPushDeviceOfThisBrowser();
        showSuccessMessage(data.message);
      } else {
        showErrorMessage(data.message);
      }
      button.disabled = false;
    }).catch(function (error) {
      showErrorMessage(error);
      button.disabled = false;
    });
  });
}

function removePushSubscriptionButton(handle, row) {
  row = row || document.querySelector(`.push-device-row[data-handle="${handle}"]`);

  fetch('endpoints/notifications/removepushsubscription.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ handle: handle }),
  })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        if (row) {
          row.remove();
        }

        const list = document.getElementById("pushDevicesList");
        if (list && !list.querySelector(".push-device-row")) {
          const noDevices = document.createElement("p");
          noDevices.id = "noPushDevices";
          noDevices.className = "push-no-devices";
          noDevices.textContent = pushText('no_devices_registered', 'No devices registered yet.');
          list.appendChild(noDevices);
        }

        markPushDeviceOfThisBrowser();
      } else {
        showErrorMessage(data.message);
      }
    })
    .catch(error => showErrorMessage(error));
}

// "Disable on this device": the browser unsubscribes its own registration and
// tells the server which row that was. It knows its endpoint and nothing else,
// so the endpoint is what it sends; the server turns it into the same handle.
function disableWebPush() {
  const button = document.getElementById("webPushDisable");
  button.disabled = true;

  navigator.serviceWorker.ready
    .then(registration => registration.pushManager.getSubscription())
    .then(function (subscription) {
      if (!subscription) {
        button.disabled = false;
        return null;
      }

      const endpoint = subscription.endpoint;

      return subscription.unsubscribe().then(function () {
        return fetch('endpoints/notifications/removepushsubscription.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': window.csrfToken,
          },
          body: JSON.stringify({ endpoint: endpoint }),
        });
      }).then(response => response.json()).then(function (data) {
        if (data.success) {
          showSuccessMessage(pushText('web_push_disabled', 'Web Push disabled on this device.'));
          markPushDeviceOfThisBrowser();
        } else {
          showErrorMessage(data.message);
        }
        button.disabled = false;
      });
    })
    .catch(function (error) {
      showErrorMessage(error);
      button.disabled = false;
    });
}

// Marks the row that belongs to this browser and offers the disable button
// only when there is something to disable.
function markPushDeviceOfThisBrowser() {
  const disableButton = document.getElementById("webPushDisable");
  const subscribeButton = document.getElementById("subscribePushButton");

  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    return;
  }

  navigator.serviceWorker.ready
    .then(registration => registration.pushManager.getSubscription())
    .then(async function (subscription) {
      document.querySelectorAll('.push-device-row .push-device-this').forEach(marker => marker.remove());

      if (!subscription) {
        if (disableButton) {
          disableButton.style.display = 'none';
        }
        if (subscribeButton) {
          subscribeButton.style.display = '';
        }
        return;
      }

      const handle = await pushDeviceHandle(subscription.endpoint);
      const row = document.querySelector(`.push-device-row[data-handle="${handle}"]`);

      if (row) {
        const marker = document.createElement('small');
        marker.className = 'push-device-this';
        marker.textContent = pushText('web_push_this_device', 'This device');
        row.querySelector('.push-device-name').appendChild(marker);
      }

      if (disableButton) {
        disableButton.style.display = row ? '' : 'none';
      }
      if (subscribeButton) {
        subscribeButton.style.display = row ? 'none' : '';
      }
    })
    .catch(() => {});
}

function testNotificationsPushButton() {
  const button = document.getElementById("testNotificationsPush");
  button.disabled = true;

  makeFetchCall('endpoints/notifications/testpushnotifications.php', {}, button);
}

function saveNotificationsPushButton() {
  const button = document.getElementById("saveNotificationsPush");
  button.disabled = true;

  const enabled = document.getElementById("pushenabled").checked ? 1 : 0;

  makeFetchCall('endpoints/notifications/savenotificationspush.php', { enabled: enabled }, button);
}

document.addEventListener('DOMContentLoaded', function () {
  if (document.getElementById("pushDevicesList")) {
    markPushDeviceOfThisBrowser();
  }
});
