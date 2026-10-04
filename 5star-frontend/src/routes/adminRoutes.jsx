import ComingSoon from '../components/ComingSoon';
import MobileScan from '../pages/admin/MobileScan';
import Promotions from '../pages/admin/Promotions';
import Bulk from '../pages/admin/Bulk';
import Content from '../pages/admin/Content';
import Products from '../pages/admin/Products';
import Reviews from '../pages/admin/Reviews';
import Pricing from '../pages/admin/Pricing';
import Reports from '../pages/admin/Reports';
import Payments from '../pages/admin/Payments';
import Warehouses from '../pages/admin/Warehouses';
import Vendors from '../pages/admin/Vendors';
import Orders from '../pages/admin/Orders';
import Support from '../pages/admin/Support';
import Inventory from '../pages/admin/Inventory';
import PurchaseInward from '../pages/admin/PurchaseInward';
import Dashboard from '../pages/admin/Dashboard';
import Customers from '../pages/admin/Customers';
import Cashiers from '../pages/admin/Cashiers';
import ProfitLoss from '../pages/admin/ProfitLoss';
import Invoices from '../pages/admin/Invoices';
import Festivals from '../pages/admin/Festivals';
import BarcodeGenerator from '../pages/admin/BarcodeGenerator';
import Wallets from '../pages/admin/Wallets';
import AccessControl from '../pages/admin/AccessControl';
import StockAudit from '../pages/admin/StockAudit';
import CustomerDues from '../pages/admin/CustomerDues';
import Backups from '../pages/admin/Backups';

/**
 * Admin console routes, rendered inside AdminLayout. The sidebar (see
 * AdminLayout's NAV) only links the ~17 everyday screens; the rest exist and
 * are reachable by direct URL, same as the live admin/*.html console.
 * Each `element` is swapped from <ComingSoon/> for the real page component as
 * it's ported — see the `note` on each for which live file to port from.
 */
export const adminRoutes = [
  { index: true, element: <Dashboard /> },
  { path: 'orders', element: <Orders /> },
  { path: 'payments', element: <Payments /> },
  { path: 'support', element: <Support /> },
  { path: 'reviews', element: <Reviews /> },
  { path: 'products', element: <Products /> },
  { path: 'inventory', element: <Inventory /> },
  { path: 'purchase-inward', element: <PurchaseInward /> },
  { path: 'mobile', element: <MobileScan /> },
  { path: 'pricing', element: <Pricing /> },
  { path: 'vendors', element: <Vendors /> },
  { path: 'warehouses', element: <Warehouses /> },
  { path: 'promotions', element: <Promotions /> },
  { path: 'content', element: <Content /> },
  { path: 'bulk', element: <Bulk /> },
  { path: 'customers', element: <Customers /> },
  { path: 'reports', element: <Reports /> },

  // Reachable by direct URL only (not in the sidebar), same as live admin/console.js.
  { path: 'loyalty', element: <ComingSoon title="Loyalty" note="Porting from admin/assets/page-loyalty.js" /> },
  { path: 'marketing', element: <ComingSoon title="Marketing" note="Porting from admin/assets/page-marketing.js" /> },
  { path: 'cashiers', element: <Cashiers /> },
  { path: 'customer-dues', element: <CustomerDues /> },
  { path: 'invoices', element: <Invoices /> },
  { path: 'settings', element: <ComingSoon title="Settings" note="Porting from admin/assets/page-settings.js" /> },
  { path: 'backups', element: <Backups /> },
  { path: 'barcode-generator', element: <BarcodeGenerator /> },
  { path: 'access-control', element: <AccessControl /> },
  { path: 'festivals', element: <Festivals /> },
  { path: 'shipments', element: <ComingSoon title="Shipments" note="Porting from admin/assets/page-shipments.js" /> },
  { path: 'stock-audit', element: <StockAudit /> },
  { path: 'profit-loss', element: <ProfitLoss /> },
  {
    path: 'purchase-returns',
    element: <ComingSoon title="Purchase returns" note="Porting from admin/assets/page-purchase-returns.js" />,
  },
  { path: 'wallets', element: <Wallets /> },
];
