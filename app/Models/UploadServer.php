<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UploadServer extends Model
{
    use HasFactory;

    protected $table = 'upload_servers';

    protected $fillable = [
        'entity_type',
        'batch_type',
        'records_count',
        'last_record_id',
        'last_record_timestamp',
        'status',
        'payload',
    ];

    protected $casts = [
        'records_count' => 'integer',
        'last_record_timestamp' => 'datetime',
        'payload' => 'array',
    ];

    /**
     * Helper para obtener la última subida exitosa de una entidad específica.
     *
     * @param string $entityType
     * @param string|null $batchType
     * @return \App\Models\UploadServer|null
     */
    public static function getLastSuccessfulUpload(string $entityType, ?string $batchType = null)
    {
        return self::where('entity_type', $entityType)
            ->when($batchType, function ($query) use ($batchType) {
                return $query->where('batch_type', $batchType);
            })
            ->where('status', 'completed')
            ->latest('id')
            ->first();
    }
}