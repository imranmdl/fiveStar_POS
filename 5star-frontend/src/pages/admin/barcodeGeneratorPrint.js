/**
 * Print helper for the Barcode Generator page — ported from
 * admin/assets/page-barcode-generator.js's printLabelsA4().
 *
 * Opens a small popup window with one label per item on an A4-sized sheet,
 * each rendered as a scannable barcode (via JsBarcode, loaded inside that
 * window — not this app, so browsing this screen never pays for the library
 * unless someone actually prints), and triggers the browser print dialog
 * once every barcode has drawn.
 *
 * Kept separate from purchaseInwardPrint.js's printBarcodeLabels() (a single
 * stacked-column popup for one or two labels at a time) because this one
 * needs a 2-up A4 grid sized for a full sheet of labels, with the price
 * shown under each item name — the two don't share a layout.
 */
import { toast } from '../../components/admin/toast';
import { printLabels } from '../../lib/barcodeLabels.js';

/**
 * Now prints through lib/barcodeLabels.js: same A4 sheet, but each label also
 * shows the SKU, and codes that aren't EAN-13 (letters, other lengths) print
 * as Code 128 instead of silently failing to draw.
 */
export function printBarcodeSheetA4(items) {
  printLabels(items, { layout: 'sheet' }).then((count) => {
    if (count === 0) toast('Nothing to print — these items have no SKU or barcode yet.', 'warning');
  });
}
