// CodeQL検証専用：意図的に危険なコードを含む
// 実際のアプリからは読み込まない

function showMessage() {
  const message = new URLSearchParams(
    window.location.search
  ).get("message");

  document.getElementById("result").textContent = message ?? "";
}

showMessage();
