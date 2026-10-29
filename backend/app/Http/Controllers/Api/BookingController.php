<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Draft;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** Vehicle bookings: a customer's advance holds a vehicle until the booking is completed, cancelled or expires. */
class BookingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['index', 'show']),
            new Middleware('permission:sales.create', only: ['store', 'update']),
            new Middleware('permission:sales.cancel', only: ['cancel']),
        ];
    }

    public function __construct(private BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:active,cancelled,expired']]);
        $this->bookings->expireDue();

        // a saved sale draft is a booking in the making (it holds its vehicle), so drafts are listed with the active bookings
        $drafts = $request->user()->hasPermission('sales.create') ? Draft::where('type', 'sale') : null;

        // a booking that became a sale leaves this list: its history is on the sale's page
        $base = Booking::query()->where('status', '!=', 'completed')->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
            ->whereHas('customer', fn ($c) => $c->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('alt_phone', 'like', "%$s%"))
            ->orWhereHas('vehicle', fn ($v) => $v->where('chassis_no', 'like', "%$s%")->orWhere('engine_no', 'like', "%$s%")->orWhere('model', 'like', "%$s%")->orWhere('brand', 'like', "%$s%"))));

        // the tabs' badges: how many bookings are in each state, for the current search; the drafts count as active
        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $draftCount = $drafts ? (clone $drafts)->count() : 0;
        $counts->put('active', ($counts['active'] ?? 0) + $draftCount);

        $bookings = $base->with(['vehicle:id,chassis_no,engine_no,brand,model,year,vehicle_type,status', 'customer:id,name,phone,alt_phone', 'creator:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("status = 'active' desc")->orderByRaw('expires_on is null')->orderBy('expires_on')->latest('id')
            ->paginate($this->perPage($request));

        // the drafts ride along on the first page of the Active and All lists
        $withDrafts = $drafts && in_array($request->status, [null, '', 'active'], true) && $bookings->currentPage() === 1;
        $extra = ['status_counts' => $counts];
        if ($withDrafts) {
            $extra['drafts'] = Draft::presentMany((clone $drafts)->with('creator:id,name')->latest('updated_at')->get());
        }

        return $this->paginated($bookings, BookingResource::class, extra: $extra);
    }

    public function show(Booking $booking): JsonResponse
    {
        $this->bookings->expireDue();

        return $this->success(BookingResource::make($booking->refresh()->load(['vehicle', 'customer'])));
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->create($request->validated(), $request->user());

        return $this->success(BookingResource::make($booking->load(['vehicle', 'customer'])), 'Vehicle booked', 201);
    }

    public function update(UpdateBookingRequest $request, Booking $booking): JsonResponse
    {
        $booking = $this->bookings->update($booking, $request->validated());

        return $this->success(BookingResource::make($booking->load(['vehicle', 'customer'])), 'Booking updated');
    }

    public function cancel(CancelBookingRequest $request, Booking $booking): JsonResponse
    {
        $booking = $this->bookings->cancel($booking, $request->filled('refund_amount') ? (float) $request->refund_amount : null, $request->note);

        return $this->success(BookingResource::make($booking->load(['vehicle', 'customer'])), 'Booking cancelled: the vehicle is back in stock');
    }
}
