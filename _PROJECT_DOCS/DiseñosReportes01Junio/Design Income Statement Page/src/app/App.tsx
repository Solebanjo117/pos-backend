import { Toaster } from "sonner";
import { IncomeStatement } from "./components/IncomeStatement";

export default function App() {
  return (
    <>
      <IncomeStatement />
      <Toaster
        position="top-right"
        toastOptions={{
          style: {
            background: "#171a26",
            border: "1px solid #252830",
            color: "#e2e8f0",
            fontFamily: "Inter, sans-serif",
            fontSize: 13,
          },
        }}
      />
    </>
  );
}
