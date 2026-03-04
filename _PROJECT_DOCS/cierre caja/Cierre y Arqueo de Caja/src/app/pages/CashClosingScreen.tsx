import { useState, useMemo } from 'react';
import { CashSummary } from '../components/pos/CashSummary';
import { CashCountTable, calculateTotalCash, CASH_ROWS } from '../components/pos/CashCountTable';
import { PaymentMethods, PAYMENT_TYPES, PaymentType } from '../components/pos/PaymentMethods';
import { CheckCircle2, RotateCcw, X, Calculator } from 'lucide-react';
import { formatCurrency } from '../utils/format';

// Mock data for initial values
const INITIAL_FUND = 200.00;
const EXPECTED_CASH_SALES = 1450.50;
const EXPECTED_ELECTRONIC_PAYMENTS = 850.75;
const EXPECTED_TOTAL_DAY = INITIAL_FUND + EXPECTED_CASH_SALES + EXPECTED_ELECTRONIC_PAYMENTS;

export function CashClosingScreen() {
  const [cashCounts, setCashCounts] = useState<Record<string, number>>(() => 
    CASH_ROWS.reduce((acc, row) => ({ 
      ...acc, 
      [`unit_${row.id}`]: 0,
      [`pack_${row.id}`]: 0 
    }), {})
  );

  const [payments, setPayments] = useState<Record<PaymentType, number>>({
    'visa': 0, 'mastercard': 0, 'clave': 0, 'amex': 0, 'yappy': 0, 
    'transferencia': 0, 'notaCredito': 0, 'creditoCliente': 0
  });

  const totalCashCounted = useMemo(() => {
    return calculateTotalCash(cashCounts);
  }, [cashCounts]);

  const totalElectronicCounted = useMemo(() => {
    return PAYMENT_TYPES.reduce((acc, { id }) => {
      return acc + (payments[id] || 0);
    }, 0);
  }, [payments]);

  const totalCounted = totalCashCounted + totalElectronicCounted;
  const difference = totalCounted - EXPECTED_TOTAL_DAY;
  
  const isBalanced = Math.abs(difference) < 0.01;

  const handleCashChange = (id: string, value: number) => {
    setCashCounts(prev => ({ ...prev, [id]: value }));
  };

  const handlePaymentChange = (id: PaymentType, value: number) => {
    setPayments(prev => ({ ...prev, [id]: value }));
  };

  const handleClose = () => {
    alert('Caja cerrada correctamente');
  };

  const handleCancel = () => {
    if (confirm('¿Estás seguro de cancelar el cierre? Se perderán los datos ingresados.')) {
      setCashCounts(CASH_ROWS.reduce((acc, row) => ({ 
        ...acc, 
        [`unit_${row.id}`]: 0,
        [`pack_${row.id}`]: 0 
      }), {}));
      setPayments({ 'visa': 0, 'mastercard': 0, 'clave': 0, 'amex': 0, 'yappy': 0, 'transferencia': 0, 'notaCredito': 0, 'creditoCliente': 0 });
    }
  };

  return (
    <div className="min-h-screen bg-gray-50 text-gray-900 font-sans flex flex-col">
      {/* Header */}
      <header className="bg-white border-b border-gray-200 sticky top-0 z-10">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 bg-gray-900 rounded-lg flex items-center justify-center text-white">
              <Calculator size={18} strokeWidth={2.5} />
            </div>
            <h1 className="text-xl font-bold tracking-tight text-gray-900">
              Cierre de Caja <span className="text-gray-400 font-normal">#2024-10-24</span>
            </h1>
          </div>
          <div className="flex items-center gap-4 text-sm text-gray-500">
            <span className="flex items-center gap-2">
              <div className="w-2 h-2 rounded-full bg-green-500"></div>
              Caja 01
            </span>
            <span className="hidden sm:inline">|</span>
            <span className="hidden sm:inline">Juan Pérez</span>
          </div>
        </div>
      </header>

      {/* Main Content */}
      <main className="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
        
        <div className="space-y-6">
          {/* Section 1: Summary */}
          <section>
            <CashSummary
              initialFund={INITIAL_FUND}
              cashSales={EXPECTED_CASH_SALES}
              electronicPayments={EXPECTED_ELECTRONIC_PAYMENTS}
              totalDay={EXPECTED_TOTAL_DAY}
              difference={difference}
            />
          </section>

          {/* Sections 2 & 3: Details Grid */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:h-[calc(100vh-320px)]">
            <section className="h-[500px] lg:h-full">
              <CashCountTable
                counts={cashCounts}
                onCountChange={handleCashChange}
              />
            </section>

            <section className="h-[500px] lg:h-full">
              <PaymentMethods
                payments={payments}
                onPaymentChange={handlePaymentChange}
              />
            </section>
          </div>
        </div>
      </main>

      {/* Footer */}
      <footer className="sticky bottom-0 z-10 bg-white border-t border-gray-200 shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.05)]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
          <div className="flex flex-col md:flex-row items-center justify-between gap-4">
            
            <div className="flex flex-wrap justify-center md:justify-start gap-x-8 gap-y-2">
              <div>
                <span className="text-xs text-gray-500 uppercase tracking-wider font-semibold block">Total Esperado</span>
                <span className="text-2xl font-bold text-gray-400">{formatCurrency(EXPECTED_TOTAL_DAY)}</span>
              </div>
              
              <div className="hidden md:block w-px bg-gray-200 h-10 self-center"></div>
              
              <div>
                <span className="text-xs text-gray-500 uppercase tracking-wider font-semibold block">Total Contado</span>
                <span className={`text-2xl font-bold ${isBalanced ? 'text-green-600' : 'text-gray-900'}`}>
                  {formatCurrency(totalCounted)}
                </span>
              </div>
              
              <div className="hidden md:block w-px bg-gray-200 h-10 self-center"></div>

              <div className="flex items-center">
                {isBalanced ? (
                  <div className="flex items-center gap-2 text-green-600 bg-green-50 px-3 py-1.5 rounded-full border border-green-100">
                    <CheckCircle2 size={20} strokeWidth={2.5} />
                    <span className="font-bold text-sm uppercase tracking-wide">Cuadra</span>
                  </div>
                ) : (
                  <div className="flex items-center gap-2 text-red-600 bg-red-50 px-3 py-1.5 rounded-full border border-red-100">
                    <X size={20} strokeWidth={2.5} />
                    <span className="font-bold text-sm uppercase tracking-wide">
                      Diferencia: {difference > 0 ? '+' : ''}{formatCurrency(difference)}
                    </span>
                  </div>
                )}
              </div>
            </div>

            <div className="flex items-center gap-3 w-full md:w-auto">
              <button
                onClick={handleCancel}
                className="flex-1 md:flex-none justify-center flex items-center gap-2 px-5 py-2.5 border border-gray-300 text-gray-700 font-semibold rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-200 transition-all cursor-pointer text-sm"
              >
                <RotateCcw size={16} />
                Cancelar
              </button>
              <button
                onClick={handleClose}
                // disabled={!isBalanced} // Optional: block closing if not balanced? User usually can close with diff but with warning.
                className={`flex-1 md:flex-none justify-center flex items-center gap-2 px-8 py-2.5 font-bold rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all cursor-pointer text-sm ${
                  isBalanced
                    ? 'bg-gray-900 text-white hover:bg-black focus:ring-gray-900'
                    : 'bg-gray-900 text-white hover:bg-black focus:ring-gray-900 opacity-90' // Allow closing anyway or warn? Standard POS allows closing with diff usually.
                }`}
              >
                <CheckCircle2 size={16} />
                Cerrar Caja
              </button>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}
