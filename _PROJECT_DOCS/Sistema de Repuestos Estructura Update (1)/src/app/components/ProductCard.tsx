import { Link } from "react-router";
import { Producto } from "../types";
import { getCategoriaById, getItemsByProductoId } from "../data/mockData";
import { Package, ShoppingCart } from "lucide-react";

interface ProductCardProps {
  producto: Producto;
}

export function ProductCard({ producto }: ProductCardProps) {
  const categoria = getCategoriaById(producto.categoriaId);
  const items = getItemsByProductoId(producto.id);
  
  // Calcular precio mínimo
  const precioMin = items.length > 0 
    ? Math.min(...items.map(item => item.precioVenta))
    : 0;

  // Marcas disponibles
  const marcasDisponibles = [...new Set(items.map(item => item.marca))];
  const totalMarcas = marcasDisponibles.length;

  return (
    <Link
      to={`/product/${producto.id}`}
      className="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-lg hover:border-blue-300 transition-all duration-200 group flex flex-col h-full"
    >
      {/* Header: Código + Categoría */}
      <div className="flex justify-between items-start mb-3">
        <div className="bg-gray-100 text-gray-600 text-xs font-mono px-2 py-0.5 rounded">
          {producto.codigo}
        </div>
        <div className="text-xs font-medium text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full">
          {categoria?.nombre}
        </div>
      </div>

      {/* Imagen del producto */}
      <div className="aspect-square bg-white rounded-lg mb-4 flex items-center justify-center overflow-hidden border border-gray-100 p-2">
        {producto.imagen ? (
          <img src={producto.imagen} alt={producto.descripcion} className="w-full h-full object-contain" />
        ) : (
          <Package className="w-12 h-12 text-gray-300" />
        )}
      </div>

      {/* Descripción */}
      <h3 className="font-bold text-gray-900 text-sm mb-2 line-clamp-2 leading-tight group-hover:text-blue-600 transition-colors flex-grow">
        {producto.descripcion}
      </h3>

      {/* Resumen de Marcas */}
      <div className="mb-4">
        <div className="text-xs text-gray-500 mb-1">Marcas disponibles:</div>
        <div className="flex flex-wrap gap-1.5">
          {marcasDisponibles.slice(0, 3).map((marca, idx) => (
            <span key={idx} className="text-xs font-medium text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded border border-gray-200">
              {marca}
            </span>
          ))}
          {totalMarcas > 3 && (
            <span className="text-xs text-gray-500 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">
              +{totalMarcas - 3}
            </span>
          )}
          {totalMarcas === 0 && (
            <span className="text-xs text-gray-400 italic">Consultar</span>
          )}
        </div>
      </div>

      {/* Footer: Precio + Botón */}
      <div className="flex items-end justify-between mt-auto pt-3 border-t border-gray-100">
        <div>
          <div className="text-[10px] text-gray-500 uppercase font-semibold">Desde</div>
          <div className="text-xl font-bold text-gray-900">
            ${precioMin.toFixed(2)}
          </div>
        </div>
        
        <button className="flex items-center gap-1.5 px-3 py-1.5 bg-blue-600 text-white text-xs font-bold rounded-lg hover:bg-blue-700 transition-colors shadow-sm group-hover:shadow">
          <ShoppingCart className="w-3.5 h-3.5" />
          Ver Opciones
        </button>
      </div>
    </Link>
  );
}
