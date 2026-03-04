import { CheckCircle2, AlertTriangle, XCircle } from 'lucide-react';
import { Badge } from './ui/badge';

interface ConciliacionFinalProps {
  totalEsperado: number;
  totalContado: number;
  diferencia: number;
}

export function ConciliacionFinal({ totalEsperado, totalContado, diferencia }: ConciliacionFinalProps) {
  const cuadra = diferencia === 0;
  const sobrante = diferencia > 0;
  const faltante = diferencia < 0;

  const getEstadoIcon = () => {
    if (cuadra) return <CheckCircle2 className="w-8 h-8 text-green-600" />;
    if (sobrante) return <AlertTriangle className="w-8 h-8 text-red-600" />;
    return <XCircle className="w-8 h-8 text-red-600" />;
  };

  const getEstadoText = () => {
    if (cuadra) return 'CUADRA';
    if (sobrante) return 'SOBRANTE';
    return 'FALTANTE';
  };

  const getEstadoBadgeVariant = (): "default" | "destructive" | "outline" | "secondary" => {
    if (cuadra) return 'default';
    return 'destructive';
  };

  return (
    <div className="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
      <div className="bg-gray-800 text-white px-6 py-4 border-b-4 border-red-600">
        <h2 className="text-lg font-bold uppercase tracking-wide">Conciliación Final</h2>
      </div>
      
      <div className="p-6 space-y-6">
        {/* Totales */}
        <div className="space-y-4">
          <div className="flex justify-between items-center pb-3 border-b border-gray-200">
            <span className="text-sm font-semibold text-gray-600 uppercase tracking-wider">
              Total Esperado en Caja
            </span>
            <span className="text-xl font-bold text-gray-900 font-mono">
              ${totalEsperado.toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </span>
          </div>

          <div className="flex justify-between items-center pb-3 border-b border-gray-200">
            <span className="text-sm font-semibold text-gray-600 uppercase tracking-wider">
              Total Contado
            </span>
            <span className="text-xl font-bold text-gray-900 font-mono">
              ${totalContado.toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </span>
          </div>

          <div className={`flex justify-between items-center pb-3 border-b-2 ${
            cuadra ? 'border-green-500' : 'border-red-500'
          }`}>
            <span className="text-sm font-semibold text-gray-600 uppercase tracking-wider">
              Diferencia
            </span>
            <span className={`text-2xl font-bold font-mono ${
              cuadra ? 'text-green-600' : 'text-red-600'
            }`}>
              ${Math.abs(diferencia).toLocaleString('es-PA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
            </span>
          </div>
        </div>

        {/* Estado */}
        <div className={`rounded-lg p-6 border-2 ${
          cuadra 
            ? 'bg-green-50 border-green-500' 
            : 'bg-red-50 border-red-500'
        }`}>
          <div className="flex items-center justify-between mb-4">
            <span className="text-sm font-semibold text-gray-700 uppercase tracking-wider">
              Estado
            </span>
            {getEstadoIcon()}
          </div>
          
          <div className="flex items-center justify-center">
            <Badge 
              variant={getEstadoBadgeVariant()}
              className={`text-lg font-bold px-6 py-2 ${
                cuadra 
                  ? 'bg-green-600 hover:bg-green-700 text-white' 
                  : 'bg-red-600 hover:bg-red-700 text-white'
              }`}
            >
              {getEstadoText()}
            </Badge>
          </div>

          {!cuadra && (
            <div className="mt-4 pt-4 border-t border-gray-300">
              <p className="text-center text-sm font-semibold text-gray-700">
                {sobrante ? 'Excedente' : 'Déficit'} detectado
              </p>
              <p className="text-center text-xs text-gray-600 mt-1">
                Verificar conteo antes de cerrar caja
              </p>
            </div>
          )}
        </div>

        {/* Información adicional */}
        <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
          <p className="text-xs text-gray-600 leading-relaxed">
            <strong className="text-gray-800">Nota:</strong> Asegúrese de revisar todos los montos antes de proceder con el cierre de caja. 
            Esta operación quedará registrada en el sistema.
          </p>
        </div>
      </div>
    </div>
  );
}
