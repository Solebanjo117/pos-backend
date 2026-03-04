import { formatCurrency } from "../../utils/format";

export type PaymentType =
  | 'visa'
  | 'mastercard'
  | 'clave'
  | 'amex'
  | 'yappy'
  | 'transferencia'
  | 'notaCredito'
  | 'creditoCliente';

export const PAYMENT_TYPES: { id: PaymentType; label: string }[] = [
  { id: 'visa', label: 'Visa' },
  { id: 'mastercard', label: 'Mastercard' },
  { id: 'clave', label: 'Clave' },
  { id: 'amex', label: 'AMEX' },
  { id: 'yappy', label: 'Yappy' },
  { id: 'transferencia', label: 'Transferencia' },
  { id: 'notaCredito', label: 'Nota de Crédito' },
  { id: 'creditoCliente', label: 'Crédito Cliente' },
];

interface PaymentMethodsProps {
  payments: Record<PaymentType, number>;
  onPaymentChange: (id: PaymentType, value: number) => void;
}

export function PaymentMethods({ payments, onPaymentChange }: PaymentMethodsProps) {
  const total = PAYMENT_TYPES.reduce(
    (acc, { id }) => acc + (payments[id] || 0),
    0
  );

  return (
    <div className="bg-white p-4 rounded-lg shadow-sm border border-gray-100 h-full flex flex-col">
      <h3 className="text-sm font-semibold text-gray-700 mb-4 border-b border-gray-100 pb-2">
        Otros Métodos de Pago
      </h3>
      
      <div className="overflow-auto flex-1">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-4">
          {PAYMENT_TYPES.map((type) => (
            <div key={type.id} className="flex flex-col">
              <label htmlFor={`payment-${type.id}`} className="text-xs font-medium text-gray-500 mb-1">
                {type.label}
              </label>
              <div className="relative">
                <span className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                  $
                </span>
                <input
                  id={`payment-${type.id}`}
                  type="number"
                  min="0"
                  step="0.01"
                  value={payments[type.id] === 0 ? '' : payments[type.id]}
                  onChange={(e) => {
                    const val = parseFloat(e.target.value);
                    onPaymentChange(type.id, isNaN(val) ? 0 : val);
                  }}
                  className="w-full border border-gray-200 rounded px-3 py-2 pl-7 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 text-gray-900 transition-shadow"
                  placeholder="0.00"
                />
              </div>
            </div>
          ))}
        </div>
      </div>

      <div className="mt-4 pt-4 border-t border-gray-100 flex justify-between items-center bg-gray-50 p-3 rounded-md">
        <span className="text-sm font-semibold text-gray-600">Total Electrónicos</span>
        <span className="text-lg font-bold text-gray-900">{formatCurrency(total)}</span>
      </div>
    </div>
  );
}
