<?php

namespace App\Http\Controllers\Api\Gateway\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\StoreGatewayConfigRequest;
use App\Http\Requests\Gateway\UpdateGatewayConfigRequest;
use App\Http\Requests\PhotoRequest;
use App\Http\Resources\Gateway\GatewayConfigResource;
use App\Http\Resources\Gateway\GatewayLogResource;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayLog;
use App\Services\Gateway\GatewayRegistry;
use App\Services\Gateway\GatewaySettingsService;
use App\Support\Photos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Gateway settings, super admin only (the route group enforces the role). */
class GatewayConfigController extends Controller
{
    public function __construct(private GatewaySettingsService $settings, private GatewayRegistry $registry) {}

    public function index(): JsonResponse
    {
        $configs = GatewayConfig::whereIn('code', GatewayRegistry::codes())->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (GatewayConfig $c) => GatewayConfigResource::make($c)->additional(['configured' => $this->settings->describe($c)['configured']]));

        return $this->success($configs, 'OK', 200, ['addable' => $this->settings->addable()]);
    }

    public function store(StoreGatewayConfigRequest $request): JsonResponse
    {
        $gateway = $this->settings->create($request->validated(), $request->user(), $request->ip());
        $details = $this->settings->describe($gateway);

        return $this->success(GatewayConfigResource::make($gateway)->additional(['configured' => $details['configured'], 'details' => $details]), 'Gateway added', 201);
    }

    public function show(GatewayConfig $gateway): JsonResponse
    {
        $details = $this->settings->describe($gateway);

        return $this->success(GatewayConfigResource::make($gateway)->additional(['configured' => $details['configured'], 'details' => $details]));
    }

    public function update(UpdateGatewayConfigRequest $request, GatewayConfig $gateway): JsonResponse
    {
        $gateway = $this->settings->update($gateway, $request->validated(), $request->user(), $request->ip());
        $details = $this->settings->describe($gateway);

        return $this->success(GatewayConfigResource::make($gateway)->additional(['configured' => $details['configured'], 'details' => $details]), 'Gateway settings saved');
    }

    public function logo(PhotoRequest $request, GatewayConfig $gateway): JsonResponse
    {
        Photos::delete($gateway->logo_path);
        $gateway->update(['logo_path' => Photos::store($request->file('photo'), 'gateways')]);
        AdminAuditLog::record($request->user(), 'gateway.updated', $gateway, [['field' => 'logo', 'to' => 'replaced']], $request->ip());

        return $this->success(GatewayConfigResource::make($gateway), 'Logo updated');
    }

    public function test(GatewayConfig $gateway): JsonResponse
    {
        $error = $this->settings->testConnection($gateway, $this->registry);

        return $error === null
            ? $this->success(['ok' => true], 'The gateway answered: the '.$gateway->active_mode.' credentials work.')
            : $this->success(['ok' => false, 'error' => $error], 'The gateway refused the connection.');
    }

    public function logs(Request $request, GatewayConfig $gateway): JsonResponse
    {
        $logs = GatewayLog::where('gateway_code', $gateway->code)
            ->when($request->attempt_id, fn ($q, $id) => $q->where('attempt_id', $id))
            ->latest('id')->paginate($this->perPage($request, 50));

        return $this->paginated($logs, GatewayLogResource::class);
    }
}
