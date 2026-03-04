import { createBrowserRouter } from "react-router";
import Home from "./pages/Home";
import VehicleSelector from "./pages/VehicleSelector";
import Catalog from "./pages/Catalog";
import ProductDetail from "./pages/ProductDetail";

export const router = createBrowserRouter([
  {
    path: "/",
    Component: Home,
  },
  {
    path: "/vehicle-selector",
    Component: VehicleSelector,
  },
  {
    path: "/catalog",
    Component: Catalog,
  },
  {
    path: "/product/:id",
    Component: ProductDetail,
  },
  {
    path: "*",
    Component: () => (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <h1 className="text-4xl font-bold mb-4">404</h1>
          <p className="text-gray-600">Página no encontrada</p>
        </div>
      </div>
    ),
  },
]);
