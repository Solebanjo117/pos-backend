import { createBrowserRouter } from "react-router";
import { CashClosingScreen } from "./pages/CashClosingScreen";

export const router = createBrowserRouter([
  {
    path: "/",
    Component: CashClosingScreen,
  },
]);
