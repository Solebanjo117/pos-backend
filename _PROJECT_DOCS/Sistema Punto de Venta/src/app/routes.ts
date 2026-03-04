import { createBrowserRouter } from "react-router";
import POSLayout from "./components/pos/POSLayout";

export const router = createBrowserRouter([
  {
    path: "/",
    Component: POSLayout,
  },
]);
