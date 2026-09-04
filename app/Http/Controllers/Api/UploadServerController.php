<?php

namespace App\Http\Controllers\Api;

use App\Models\UploadServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UploadServerController extends Controller
{
    /**
     * Muestra una lista de los registros de subidas paginados.
     * Permite filtrar por tipo de entidad (entity_type) o estado (status).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $query = UploadServer::query();

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->has('batch_type')) {
            $query->where('batch_type', $request->input('batch_type'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $uploads = $query->latest('id')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $uploads
        ], 200);
    }

    /**
     * Guarda un nuevo registro de subida al servidor.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type'           => 'required|string|max:255',
            'batch_type'            => 'nullable|string|max:255',
            'records_count'         => 'required|integer|min:0',
            'last_record_id'        => 'nullable|string|max:255',
            'last_record_timestamp' => 'nullable|date',
            'status'                => 'nullable|string|in:completed,failed,in_progress',
            'payload'               => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        $upload = UploadServer::create([
            'entity_type'           => $request->input('entity_type'),
            'batch_type'            => $request->input('batch_type'),
            'records_count'         => $request->input('records_count', 0),
            'last_record_id'        => $request->input('last_record_id'),
            'last_record_timestamp' => $request->input('last_record_timestamp'),
            'status'                => $request->input('status', 'completed'),
            'payload'               => $request->input('payload'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registro de subida almacenado con éxito.',
            'data'    => $upload
        ], 201);
    }

    /**
     * Muestra la información detallada de una subida específica.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $upload = UploadServer::find($id);

        if (!$upload) {
            return response()->json([
                'success' => false,
                'message' => 'Registro de subida no encontrado.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $upload
        ], 200);
    }

    /**
     * Obtiene el último registro de subida exitoso según el tipo de entidad.
     *
     * @param Request $request
     * @param string $entityType
     * @return JsonResponse
     */
    public function getLastUpload(Request $request, string $entityType): JsonResponse
    {
        $batchType = $request->query('batch_type');

        $lastUpload = UploadServer::getLastSuccessfulUpload($entityType, $batchType);

        if (!$lastUpload) {
            return response()->json([
                'success' => false,
                'message' => "No se encontraron registros de subidas exitosas para la entidad '{$entityType}'."
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $lastUpload
        ], 200);
    }
}