import { useState } from 'react';
import { api } from '../../lib/api';

export const EMPTY_ADDRESS = {
  contact_name: '', contact_mobile: '', address_line1: '', address_line2: '', city: '', state: '', pincode: '',
};

function fromAddress(address) {
  if (!address) return EMPTY_ADDRESS;
  return {
    contact_name: address.contact_name || '',
    contact_mobile: address.contact_mobile || '',
    address_line1: address.address_line1 || '',
    address_line2: address.address_line2 || '',
    city: address.city || '',
    state: address.state || '',
    pincode: address.pincode || '',
  };
}

/**
 * Delivery address form, shared by checkout and My account. With `address`
 * it edits that address (PATCH); without, it adds a new one (POST).
 * `onSaved` receives the saved address.
 */
export default function AddressForm({ address = null, onSaved, onCancel, submitLabel }) {
  const [form, setForm] = useState(() => fromAddress(address));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const set = (field) => (event) => setForm((f) => ({ ...f, [field]: event.target.value }));

  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = address
        ? await api.patch(`/addresses/${encodeURIComponent(address.uuid)}`, form)
        : await api.post('/addresses', form);
      if (!address) setForm(EMPTY_ADDRESS);
      onSaved(response.data.address);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="sf-form-grid" onSubmit={handleSubmit}>
      {error && <div className="sf-error sf-field--full">{error}</div>}
      <label className="sf-field">Full name<input required placeholder="Priya Sharma" autoComplete="name" value={form.contact_name} onChange={set('contact_name')} /></label>
      <label className="sf-field">Mobile<input required inputMode="numeric" maxLength={10} placeholder="9876543210" autoComplete="tel-national" value={form.contact_mobile} onChange={set('contact_mobile')} /></label>
      <label className="sf-field sf-field--full">Address<input required placeholder="House no., street, area" autoComplete="address-line1" value={form.address_line1} onChange={set('address_line1')} /></label>
      <label className="sf-field sf-field--full">Landmark (optional)<input placeholder="Near…" autoComplete="address-line2" value={form.address_line2} onChange={set('address_line2')} /></label>
      <label className="sf-field">Pincode<input required inputMode="numeric" maxLength={6} placeholder="560001" autoComplete="postal-code" value={form.pincode} onChange={set('pincode')} /></label>
      <label className="sf-field">City<input required placeholder="Bengaluru" autoComplete="address-level2" value={form.city} onChange={set('city')} /></label>
      <label className="sf-field">State<input required placeholder="Karnataka" autoComplete="address-level1" value={form.state} onChange={set('state')} /></label>
      <div className="sf-field--full" style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <button type="submit" className="sf-btn sf-btn--red" disabled={busy}>
          {busy ? 'Saving…' : submitLabel || (address ? 'SAVE ADDRESS' : 'ADD ADDRESS')}
        </button>
        {onCancel && <button type="button" className="sf-btn sf-btn--ghost" onClick={onCancel}>CANCEL</button>}
      </div>
    </form>
  );
}
