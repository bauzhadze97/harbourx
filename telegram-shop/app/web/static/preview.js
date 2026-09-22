/* Drives /admin/preview/api and paints the result as a Telegram-ish chat. */
(function () {
  "use strict";
  var csrf = document.currentScript.dataset.csrf;
  var chat = document.getElementById("pv-chat");
  var toast = document.getElementById("pv-toast");
  var balance = document.getElementById("pv-balance");

  function userId() { return document.getElementById("pv-user").value.trim(); }
  function userName() { return document.getElementById("pv-name").value.trim(); }

  async function call(body) {
    body.csrf = csrf;
    body.user_id = userId();
    body.name = userName();
    var res = await fetch("/admin/preview/api", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });
    var data = await res.json();
    if (data.error) { toast.textContent = "⚠️ " + data.error; return; }
    paint(data);
  }

  function paint(data) {
    chat.innerHTML = "";
    (data.screen.messages || []).forEach(function (m) {
      var wrap = document.createElement("div");
      wrap.className = "bubble" + (m.from_user ? " mine" : "");
      if (m.photo) {
        var img = document.createElement("img");
        img.className = "bubble-photo";
        img.src = "/media/" + m.photo.split("/").pop();
        img.alt = "";
        wrap.appendChild(img);
      }
      var body = document.createElement("div");
      body.className = "bubble-text";
      body.innerHTML = m.text;            // handler output, already HTML-escaped
      wrap.appendChild(body);
      (m.keyboard || []).forEach(function (row) {
        var line = document.createElement("div");
        line.className = "kb-row";
        row.forEach(function (b) {
          var el;
          if (b.url) {
            el = document.createElement("a");
            el.href = b.url; el.target = "_blank"; el.rel = "noopener";
          } else {
            el = document.createElement("button");
            el.addEventListener("click", function () {
              call({ action: "press", message_id: m.id, data: b.data });
            });
          }
          el.className = "kb-btn";
          el.textContent = b.text;
          line.appendChild(el);
        });
        wrap.appendChild(line);
      });
      chat.appendChild(wrap);
    });
    chat.scrollTop = chat.scrollHeight;
    toast.textContent = (data.screen.toasts || []).join(" · ") || "—";
    balance.textContent = data.balance + " ₾ · " + data.purchases + " ყიდვა";
  }

  document.getElementById("pv-start").addEventListener("click", function () {
    call({ action: "text", text: "/start" });
  });
  document.getElementById("pv-reset").addEventListener("click", function () {
    call({ action: "reset" });
  });
  function send() {
    var input = document.getElementById("pv-text");
    var text = input.value.trim();
    if (!text) return;
    input.value = "";
    call({ action: "text", text: text });
  }
  document.getElementById("pv-send").addEventListener("click", send);
  document.getElementById("pv-text").addEventListener("keydown", function (e) {
    if (e.key === "Enter") send();
  });

  call({ action: "state" });
})();
