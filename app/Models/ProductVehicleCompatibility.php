<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class ProductVehicleCompatibility extends Model
{
    use HasFactory;

    protected $table = 'product_vehicle_compatibility';

    protected $fillable = [
        'product_id',
        'scope_type',
        'make_id',
        'model_id',
        'engine_id',
        'chassis_id',
        'raw_vehicle_key',
        'raw_vehicle_text',
        'notes',
        'all_engines',
        'all_chassis',
        'is_default',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'make_id' => 'integer',
        'model_id' => 'integer',
        'engine_id' => 'integer',
        'chassis_id' => 'integer',
        'all_engines' => 'boolean',
        'all_chassis' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Todo producto con carros tiene exactamente un carro principal: el primero que se agrega
     * queda como principal y, si se borra el principal, pasa al siguiente más antiguo.
     */
    protected static function booted(): void
    {
        static::created(function (ProductVehicleCompatibility $compatibility): void {
            self::ensureDefault((int) $compatibility->product_id);
        });

        // Siempre se revisa: la instancia en memoria puede no saber que era la principal
        // (se marca con una consulta aparte). Si queda otro principal, ensureDefault lo conserva.
        static::deleted(function (ProductVehicleCompatibility $compatibility): void {
            self::ensureDefault((int) $compatibility->product_id);
        });
    }

    /**
     * Deja un solo carro principal en el producto. Usa $preferredId si es válido; si no, conserva
     * el principal actual o, si no hay, el más antiguo. Los registros "universal" no pueden ser
     * principales. Llamar después de borrados masivos (que no disparan eventos del modelo).
     */
    public static function ensureDefault(int $productId, ?int $preferredId = null): ?int
    {
        return DB::transaction(function () use ($productId, $preferredId): ?int {
            $eligible = fn () => static::query()
                ->where('product_id', $productId)
                ->where(function ($query): void {
                    $query->whereNull('scope_type')->orWhere('scope_type', '!=', 'universal');
                });

            $targetId = null;
            if ($preferredId && $eligible()->whereKey($preferredId)->exists()) {
                $targetId = $preferredId;
            }
            $targetId ??= $eligible()->where('is_default', true)->orderBy('id')->value('id');
            $targetId ??= $eligible()->orderBy('id')->value('id');

            // Primero se apagan los demás y luego se enciende el elegido (respeta el índice único).
            static::query()
                ->where('product_id', $productId)
                ->where('is_default', true)
                ->when($targetId, fn ($query) => $query->whereKeyNot($targetId))
                ->update(['is_default' => false]);

            if ($targetId) {
                static::query()->whereKey($targetId)->where('is_default', false)->update(['is_default' => true]);
            }

            return $targetId ? (int) $targetId : null;
        });
    }

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the vehicle engine (nullable — record may be chassis-only).
     */
    public function engine(): BelongsTo
    {
        return $this->belongsTo(VehicleEngine::class, 'engine_id');
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /**
     * Get the vehicle chassis (nullable — record may be engine-only).
     */
    public function chassis(): BelongsTo
    {
        return $this->belongsTo(VehicleChassis::class, 'chassis_id');
    }
}
