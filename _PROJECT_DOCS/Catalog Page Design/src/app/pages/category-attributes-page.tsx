import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router';
import { ArrowLeft, Plus, Trash2, GripVertical, Save, X } from 'lucide-react';
import { Button } from '../components/ui/button';
import { Card } from '../components/ui/card';
import { Input } from '../components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/ui/select';
import { Checkbox } from '../components/ui/checkbox';
import { Label } from '../components/ui/label';
import { Badge } from '../components/ui/badge';
import { mockCategories } from '../data/mock-categories';
import { CategoryAttribute } from '../types/category';

export function CategoryAttributesPage() {
  const { categoryId } = useParams();
  const [category, setCategory] = useState(() => 
    mockCategories.find(c => c.id === categoryId)
  );
  const [attributes, setAttributes] = useState<CategoryAttribute[]>([]);
  const [hasChanges, setHasChanges] = useState(false);

  useEffect(() => {
    if (category) {
      setAttributes([...category.attributes].sort((a, b) => a.order - b.order));
    }
  }, [category]);

  if (!category) {
    return (
      <div className="container mx-auto px-4 py-6">
        <div className="text-center py-12">
          <p className="text-gray-600 mb-4">Categoría no encontrada</p>
          <Link to="/admin/categories">
            <Button variant="outline">Volver a Categorías</Button>
          </Link>
        </div>
      </div>
    );
  }

  const handleAddAttribute = () => {
    const newAttribute: CategoryAttribute = {
      id: `attr-${Date.now()}`,
      name: 'Nuevo Atributo',
      dataType: 'text',
      required: false,
      order: attributes.length + 1,
    };
    setAttributes([...attributes, newAttribute]);
    setHasChanges(true);
  };

  const handleDeleteAttribute = (id: string) => {
    setAttributes(attributes.filter(attr => attr.id !== id));
    setHasChanges(true);
  };

  const handleUpdateAttribute = (id: string, field: keyof CategoryAttribute, value: any) => {
    setAttributes(attributes.map(attr => 
      attr.id === id ? { ...attr, [field]: value } : attr
    ));
    setHasChanges(true);
  };

  const handleSave = () => {
    // Aquí normalmente guardarías en una API
    console.log('Guardando atributos:', attributes);
    setHasChanges(false);
    alert('Atributos guardados exitosamente');
  };

  const handleCancel = () => {
    if (category) {
      setAttributes([...category.attributes].sort((a, b) => a.order - b.order));
      setHasChanges(false);
    }
  };

  return (
    <div className="container mx-auto px-4 py-6 max-w-6xl">
      {/* Header */}
      <div className="mb-6">
        <Link to="/admin/categories">
          <Button variant="ghost" className="gap-2 mb-4">
            <ArrowLeft className="w-4 h-4" />
            Volver a Categorías
          </Button>
        </Link>
        
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold mb-2">Atributos de {category.name}</h1>
            <p className="text-gray-600">
              Define los atributos específicos que tendrán los productos de esta categoría
            </p>
          </div>
          
          {hasChanges && (
            <Badge variant="default" className="bg-orange-500">
              Cambios sin guardar
            </Badge>
          )}
        </div>
      </div>

      {/* Actions Bar */}
      <div className="mb-4 flex gap-2">
        <Button onClick={handleAddAttribute} className="gap-2">
          <Plus className="w-4 h-4" />
          Agregar Atributo
        </Button>
        
        {hasChanges && (
          <>
            <Button onClick={handleSave} variant="default" className="gap-2 bg-green-600 hover:bg-green-700">
              <Save className="w-4 h-4" />
              Guardar Cambios
            </Button>
            <Button onClick={handleCancel} variant="outline" className="gap-2">
              <X className="w-4 h-4" />
              Cancelar
            </Button>
          </>
        )}
      </div>

      {/* Attributes Table */}
      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50 border-b">
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">
                  Orden
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Nombre del Atributo
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">
                  Tipo de Dato
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Opciones (Select)
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">
                  Requerido
                </th>
                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                  Acciones
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {attributes.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                    No hay atributos definidos. Haz clic en "Agregar Atributo" para comenzar.
                  </td>
                </tr>
              ) : (
                attributes.map((attr, index) => (
                  <tr key={attr.id} className="hover:bg-gray-50">
                    {/* Orden */}
                    <td className="px-4 py-4">
                      <div className="flex items-center gap-2">
                        <GripVertical className="w-4 h-4 text-gray-400 cursor-move" />
                        <span className="text-sm font-medium text-gray-600">{index + 1}</span>
                      </div>
                    </td>

                    {/* Nombre */}
                    <td className="px-4 py-4">
                      <Input
                        value={attr.name}
                        onChange={(e) => handleUpdateAttribute(attr.id, 'name', e.target.value)}
                        className="w-full"
                        placeholder="Nombre del atributo"
                      />
                    </td>

                    {/* Tipo de Dato */}
                    <td className="px-4 py-4">
                      <Select
                        value={attr.dataType}
                        onValueChange={(value) => handleUpdateAttribute(attr.id, 'dataType', value)}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="text">Texto</SelectItem>
                          <SelectItem value="number">Número</SelectItem>
                          <SelectItem value="select">Selección</SelectItem>
                        </SelectContent>
                      </Select>
                    </td>

                    {/* Opciones */}
                    <td className="px-4 py-4">
                      {attr.dataType === 'select' ? (
                        <Input
                          value={attr.options?.join(', ') || ''}
                          onChange={(e) => 
                            handleUpdateAttribute(
                              attr.id, 
                              'options', 
                              e.target.value.split(',').map(s => s.trim()).filter(Boolean)
                            )
                          }
                          placeholder="Opción1, Opción2, Opción3"
                          className="w-full"
                        />
                      ) : (
                        <span className="text-sm text-gray-400">N/A</span>
                      )}
                    </td>

                    {/* Requerido */}
                    <td className="px-4 py-4">
                      <div className="flex items-center justify-center">
                        <Checkbox
                          id={`required-${attr.id}`}
                          checked={attr.required}
                          onCheckedChange={(checked) => 
                            handleUpdateAttribute(attr.id, 'required', checked)
                          }
                        />
                        <Label 
                          htmlFor={`required-${attr.id}`} 
                          className="ml-2 text-sm cursor-pointer"
                        >
                          {attr.required ? 'Sí' : 'No'}
                        </Label>
                      </div>
                    </td>

                    {/* Acciones */}
                    <td className="px-4 py-4">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => handleDeleteAttribute(attr.id)}
                        className="text-red-600 hover:text-red-700 hover:bg-red-50"
                      >
                        <Trash2 className="w-4 h-4" />
                      </Button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Info Card */}
      <Card className="mt-6 p-4 bg-blue-50 border-blue-200">
        <div className="flex gap-3">
          <div className="text-blue-600 mt-1">
            <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
            </svg>
          </div>
          <div className="flex-1">
            <h3 className="font-semibold text-blue-900 mb-1">Ayuda sobre Atributos</h3>
            <ul className="text-sm text-blue-800 space-y-1">
              <li>• <strong>Texto:</strong> Para valores alfanuméricos (ej: "M14x1.25")</li>
              <li>• <strong>Número:</strong> Para valores numéricos (ej: 150, 25.5)</li>
              <li>• <strong>Selección:</strong> Para opciones predefinidas. Separa las opciones con comas.</li>
              <li>• Los atributos requeridos deben ser completados al crear un producto.</li>
            </ul>
          </div>
        </div>
      </Card>
    </div>
  );
}
