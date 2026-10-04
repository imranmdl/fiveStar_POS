/** A dismissible toast, ported from admin/console.js's toast(). Plain DOM, no React context needed by callers. */
export function toast(message, variant = 'success') {
  let host = document.querySelector('[data-toast-host]');

  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-toast-host', '');
    host.className = 'admin-toast-host';
    document.body.appendChild(host);
  }

  const element = document.createElement('div');
  element.className = `admin-toast admin-toast--${variant}`;
  element.setAttribute('role', 'alert');
  element.innerHTML = `<span></span><button type="button" aria-label="Close">&times;</button>`;
  element.querySelector('span').textContent = message;
  element.querySelector('button').addEventListener('click', () => element.remove());
  host.appendChild(element);

  if (variant !== 'danger') {
    setTimeout(() => element.remove(), 4000);
  }
}
