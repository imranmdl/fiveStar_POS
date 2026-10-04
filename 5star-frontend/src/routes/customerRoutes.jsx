import Home from '../pages/customer/Home';
import Product from '../pages/customer/Product';
import Cart from '../pages/customer/Cart';
import Account from '../pages/customer/Account';
import Checkout from '../pages/customer/Checkout';
import Orders from '../pages/customer/Orders';
import OrderDetail from '../pages/customer/OrderDetail';
import Loyalty from '../pages/customer/Loyalty';
import Support from '../pages/customer/Support';
import SupportTicket from '../pages/customer/SupportTicket';
import Collection from '../pages/customer/Collection';
import Faq from '../pages/customer/Faq';
import Gifting from '../pages/customer/Gifting';
import Invoice from '../pages/customer/Invoice';
import Page from '../pages/customer/Page';

/**
 * Customer-facing routes, rendered inside CustomerLayout.
 * All routes are now ported to real page components (see src/pages/customer).
 */
export const customerRoutes = [
  { index: true, element: <Home /> },
  {
    path: 'product/:slug',
    element: <Product />,
  },
  {
    path: 'cart',
    element: <Cart />,
  },
  {
    path: 'account',
    element: <Account />,
  },
  {
    path: 'checkout',
    element: <Checkout />,
  },
  {
    path: 'collection/:slug',
    element: <Collection />,
  },
  {
    path: 'orders',
    element: <Orders />,
  },
  {
    path: 'orders/:uuid',
    element: <OrderDetail />,
  },
  {
    path: 'loyalty',
    element: <Loyalty />,
  },
  {
    path: 'support',
    element: <Support />,
  },
  {
    path: 'support/:uuid',
    element: <SupportTicket />,
  },
  {
    path: 'faq',
    element: <Faq />,
  },
  {
    path: 'gifting',
    element: <Gifting />,
  },
  {
    path: 'invoice/:uuid',
    element: <Invoice />,
  },
  {
    path: 'page/:slug',
    element: <Page />,
  },
];
