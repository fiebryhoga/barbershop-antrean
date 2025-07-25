<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Service;
use App\Models\DefaultOperatingHour;
use App\Models\SpecialOperatingHour;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon; 
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    
    protected static function getShopHoursForDate(Carbon $date): object
    {
        $date = $date->setTimezone(config('app.timezone'));

        $specialHours = SpecialOperatingHour::where('date', $date->toDateString())->first();
        if ($specialHours) {
            return (object) [
                'is_closed' => $specialHours->is_closed,
                'open_time' => $specialHours->open_time ? Carbon::parse($date->toDateString() . ' ' . $specialHours->open_time->format('H:i:s'), config('app.timezone')) : null,
                'close_time' => $specialHours->close_time ? Carbon::parse($date->toDateString() . ' ' . $specialHours->close_time->format('H:i:s'), config('app.timezone')) : null,
            ];
        }

        $defaultHours = DefaultOperatingHour::where('day_of_week', $date->dayOfWeek)->first();
        if ($defaultHours) {
            return (object) [
                'is_closed' => $defaultHours->is_closed,
                'open_time' => $defaultHours->open_time ? Carbon::parse($date->toDateString() . ' ' . $defaultHours->open_time->format('H:i:s'), config('app.timezone')) : null,
                'close_time' => $defaultHours->close_time ? Carbon::parse($date->toDateString() . ' ' . $defaultHours->close_time->format('H:i:s'), config('app.timezone')) : null,
            ];
        }

        return (object) [
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
        ];
    }

    /**
     * API endpoint untuk mendapatkan jam operasional toko
     */
    public function getShopHours(Request $request)
    {
        $date = Carbon::parse($request->date, config('app.timezone'));
        $shopHours = self::getShopHoursForDate($date);
        return response()->json([
            'is_closed' => $shopHours->is_closed,
            'open_time' => $shopHours->open_time ? $shopHours->open_time->format('H:i') : null,
            'close_time' => $shopHours->close_time ? $shopHours->close_time->format('H:i') : null,
        ]);
    }

    /**
     * Tampilkan form booking baru.
     */
    public function create(): \Illuminate\View\View | \Illuminate\Http\RedirectResponse
    {
        $services = Service::all(['id', 'name', 'price', 'duration_minutes']);
        $loggedInUser = Auth::user();

        if ($loggedInUser) {
            $existingActiveBooking = Booking::where('user_id', $loggedInUser->id)
                                            ->where('booking_status', 'active')
                                            ->first();
            if ($existingActiveBooking) {
                return redirect()->route('booking.show', $existingActiveBooking->id)->with('error', 'Anda sudah memiliki booking aktif. Mohon selesaikan booking sebelumnya.');
            }
        }

        return view('booking.create', [
            'services' => $services,
            'minBookingDate' => Carbon::today(config('app.timezone'))->format('Y-m-d'),
            'loggedInUser' => $loggedInUser,
        ]);
    }

    /**
     * Simpan booking baru ke database.
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $user = Auth::user();
        
        $customerNameRules = ['string', 'max:255'];
        if (!$user) {
            $customerNameRules[] = 'required';
            $customerNameRules[] = function ($attribute, $value, \Closure $fail) use ($request) {
                $existingActiveWalkin = Booking::where('customer_name', $value)
                                                ->where('booking_type', 'walk-in')
                                                ->where('booking_status', 'active')
                                                ->exists();
                if ($existingActiveWalkin) {
                    $fail('Pelanggan walk-in dengan nama ini sudah memiliki booking aktif. Gunakan nama lain atau selesaikan booking sebelumnya.');
                }
            };
        } else {
            $customerNameRules[] = 'nullable';
        }

        $rules = [
            'selectedServices' => ['required', 'array', 'min:1'],
            'selectedServices.*' => ['exists:services,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'booking_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
            'customer_name' => $customerNameRules,
            'customer_phone' => ['nullable', 'string', 'max:255'],
        ];

        $request->validate($rules + [
            'booking_date' => [
                'required', 'date', 'after_or_equal:today',
                function ($attribute, $value, $fail) {
                    $date = Carbon::parse($value, config('app.timezone'));
                    $shopHours = self::getShopHoursForDate($date);
                    if ($shopHours->is_closed) {
                        $fail('Barbershop tutup pada tanggal ini.');
                    }
                },
            ],
            'booking_time' => [
                'nullable', 'date_format:H:i',
                function ($attribute, $value, \Closure $fail) use ($request) {
                    $selectedDate = Carbon::parse($request->booking_date, config('app.timezone'));
                    $inputTime = $value ? Carbon::parse($value) : null;
                    $dateTimeInput = $inputTime ? $selectedDate->copy()->setTime($inputTime->hour, $inputTime->minute, $inputTime->second) : null;

                    $shopHours = self::getShopHoursForDate($selectedDate);

                    if ($shopHours->is_closed) {
                        $fail('Barbershop tutup pada tanggal ini.');
                        return;
                    }

                    if ($dateTimeInput) {
                        if ($shopHours->open_time && $shopHours->close_time) {
                            if (!$dateTimeInput->between($shopHours->open_time, $shopHours->close_time, true)) {
                                $fail("Jam booking harus antara {$shopHours->open_time->format('H:i')} dan {$shopHours->close_time->format('H:i')}.");
                            }
                        } else {
                            $fail('Jam operasional tidak ditemukan untuk tanggal ini. Mohon atur di pengaturan Jadwal Default.');
                        }

                        if ($selectedDate->isToday() && $dateTimeInput->lt(Carbon::now(config('app.timezone')))) {
                            $fail('Jam booking tidak bisa di masa lalu.');
                        }
                    }
                    
                    if ($dateTimeInput) {
                        $existingBookingAtSameTime = Booking::where('booking_date', $selectedDate->toDateString())
                                                            ->whereTime('booking_time', $dateTimeInput->toTimeString())
                                                            ->where('booking_status', 'active')
                                                            ->exists();
                        if ($existingBookingAtSameTime) {
                            $fail('Sudah ada booking aktif pada tanggal dan jam ini. Mohon pilih waktu lain.');
                        }
                    }
                },
            ],
        ]);

        $services = Service::find($request->selectedServices);
        $totalPrice = $services->sum('price');
        $totalDurationMinutes = $services->sum('duration_minutes');

        $bookingData = [
            'booking_type' => $user ? 'online' : 'walk-in',
            'user_id' => $user ? $user->id : null,
            'customer_name' => $user ? $user->name : $request->customer_name,
            'customer_phone' => $user ? (Auth::user()->phone ?? null) : $request->customer_phone,
            'booking_date' => $request->booking_date,
            'booking_time' => $request->booking_time ? Carbon::parse($request->booking_time)->format('H:i:s') : null,
            'total_price' => $totalPrice,
            'total_duration_minutes' => $totalDurationMinutes,
            'notes' => $request->notes,
            'arrival_status' => 'waiting',
            'booking_status' => 'active',
        ];

        $booking = null;

        DB::transaction(function () use ($bookingData, $request, &$booking) {
            $booking = Booking::create($bookingData);
            $booking->services()->attach($request->selectedServices);
            Booking::recalculateQueueNumbersAndSortOrder($booking->booking_date->toDateString());
        });

        return redirect()->route('booking.show', $booking->id)->with('success', 'Booking berhasil dibuat! Nomor antrean Anda: ' . $booking->queue_number);
    }

    /**
     * Tampilkan detail booking (untuk pelanggan).
     */
    public function show(Booking $booking): \Illuminate\View\View
    {
        
        if (Auth::user() && Auth::user()->id !== $booking->user_id && Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized action.');
        }

        $booking->load('services');

        $queuePosition = '-';
        $estimatedWaitTime = 'Tidak tersedia'; 

        if ($booking->booking_status === 'active') {
            
            
            Booking::recalculateQueueNumbersAndSortOrder($booking->booking_date->toDateString());
            $booking->refresh(); 

            
            
            $queuePosition = $booking->sort_order ?? '-';

            
            if ($booking->booking_time) {
                
                $scheduledTime = Carbon::parse($booking->booking_date->toDateString() . ' ' . $booking->booking_time->format('H:i:s'), config('app.timezone'));
                $now = Carbon::now(config('app.timezone'));

                if ($scheduledTime->greaterThan($now)) {
                    $diffInMinutes = $now->diffInMinutes($scheduledTime);
                    $hours = floor($diffInMinutes / 60);
                    $minutes = $diffInMinutes % 60;
                    
                    $estimatedWaitTime = '';
                    if ($hours > 0) {
                        $estimatedWaitTime .= $hours . ' jam ';
                    }
                    $estimatedWaitTime .= $minutes . ' menit lagi';
                } else {
                    $estimatedWaitTime = 'Sekarang atau sudah lewat'; 
                }
            } else {
                
                $estimatedWaitTime = 'Tidak tersedia untuk walk-in';
            }
            
        }

        return view('booking.show', [
            'booking' => $booking,
            'queuePosition' => $queuePosition,
            'estimatedWaitTime' => $estimatedWaitTime,
        ]);
    }

    /**
     * Tampilkan form edit booking.
     */
    public function edit(Booking $booking): \Illuminate\View\View | \Illuminate\Http\RedirectResponse
    {
        
        if (Auth::user() && Auth::user()->id !== $booking->user_id && Auth::user()->role !== 'admin') {
            abort(403, 'Anda tidak diizinkan mengedit booking ini.');
        }

        
        if ($booking->booking_status !== 'active') {
            return redirect()->route('booking.show', $booking->id)->with('error', 'Booking ini tidak dapat diedit karena statusnya tidak aktif.');
        }

        $services = Service::all(['id', 'name', 'price', 'duration_minutes']);
        
        return view('booking.edit', [
            'booking' => $booking->load('services'), 
            'services' => $services,
            'minBookingDate' => Carbon::today(config('app.timezone'))->format('Y-m-d'),
        ]);
    }

    /**
     * Update booking yang ada.
     */
    public function update(Request $request, Booking $booking): \Illuminate\Http\RedirectResponse
    {
        
        if (Auth::user() && Auth::user()->id !== $booking->user_id && Auth::user()->role !== 'admin') {
            abort(403, 'Anda tidak diizinkan memperbarui booking ini.');
        }

        
        if ($booking->booking_status !== 'active') {
            return redirect()->route('booking.show', $booking->id)->with('error', 'Booking ini tidak dapat diupdate karena statusnya tidak aktif.');
        }

        $rules = [
            'selectedServices' => ['required', 'array', 'min:1'],
            'selectedServices.*' => ['exists:services,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'booking_time' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];

        $request->validate($rules + [
            'booking_date' => [
                'required', 'date', 'after_or_equal:today',
                function ($attribute, $value, \Closure $fail) {
                    $date = Carbon::parse($value, config('app.timezone'));
                    $shopHours = self::getShopHoursForDate($date);
                    if ($shopHours->is_closed) {
                        $fail('Barbershop tutup pada tanggal ini.');
                    }
                },
            ],
            'booking_time' => [
                'nullable', 'date_format:H:i',
                function ($attribute, $value, \Closure $fail) use ($request, $booking) {
                    $selectedDate = Carbon::parse($request->booking_date, config('app.timezone'));
                    $inputTime = $value ? Carbon::parse($value) : null;
                    $dateTimeInput = $inputTime ? $selectedDate->copy()->setTime($inputTime->hour, $inputTime->minute, $inputTime->second) : null;

                    $shopHours = self::getShopHoursForDate($selectedDate);

                    if ($shopHours->is_closed) {
                        $fail('Barbershop tutup pada tanggal ini.');
                        return;
                    }

                    if ($dateTimeInput) {
                        if ($shopHours->open_time && $shopHours->close_time) {
                            if (!$dateTimeInput->between($shopHours->open_time, $shopHours->close_time, true)) {
                                            $fail("Jam booking harus antara {$shopHours->open_time->format('H:i')} dan {$shopHours->close_time->format('H:i')}.");
                                        }
                        } else {
                            $fail('Jam operasional tidak ditemukan untuk tanggal ini. Mohon atur di pengaturan Jadwal Default.');
                        }

                        if ($selectedDate->isToday() && $dateTimeInput->lt(Carbon::now(config('app.timezone')))) {
                            $fail('Jam booking tidak bisa di masa lalu.');
                        }
                    }
                    
                    if ($dateTimeInput) {
                        $existingBookingAtSameTime = Booking::where('booking_date', $selectedDate->toDateString())
                                                            ->whereTime('booking_time', $dateTimeInput->toTimeString())
                                                            ->where('booking_status', 'active')
                                                            ->where('id', '!=', $booking->id)
                                                            ->exists();
                        if ($existingBookingAtSameTime) {
                            $fail('Sudah ada booking aktif pada tanggal dan jam ini. Mohon pilih waktu lain.');
                        }
                    }
                },
            ],
        ]);

        $services = Service::find($request->selectedServices);
        $totalPrice = $services->sum('price');
        $totalDurationMinutes = $services->sum('duration_minutes');

        $bookingData = [
            'booking_date' => $request->booking_date,
            'booking_time' => $request->booking_time ? Carbon::parse($request->booking_time)->format('H:i:s') : null,
            'total_price' => $totalPrice,
            'total_duration_minutes' => $totalDurationMinutes,
            'notes' => $request->notes,
        ];
        
        DB::transaction(function () use ($booking, $bookingData, $request) {
            $booking->update($bookingData);
            $booking->services()->sync($request->selectedServices);
            Booking::recalculateQueueNumbersAndSortOrder($booking->booking_date->toDateString());
        });

        return redirect()->route('booking.show', $booking->id)->with('success', 'Booking berhasil diperbarui!');
    }

    /**
     * Batalkan booking pelanggan.
     */
    public function destroy(Booking $booking): \Illuminate\Http\RedirectResponse
    {
        
        if (Auth::user() && Auth::user()->id !== $booking->user_id && Auth::user()->role !== 'admin') {
            abort(403, 'Anda tidak diizinkan membatalkan booking ini.');
        }

        
        if ($booking->booking_status !== 'active') {
            return redirect()->route('booking.show', $booking->id)->with('error', 'Booking ini tidak dapat dibatalkan karena statusnya tidak aktif.');
        }

        DB::transaction(function () use ($booking) {
            $booking->update([
                'booking_status' => 'cancelled',
                'arrival_status' => 'cancelled',
                'sort_order' => null,
                'estimated_turn_time' => null,
            ]);
            Booking::recalculateQueueNumbersAndSortOrder($booking->booking_date->toDateString());
        });

        return redirect()->route('booking.history')->with('success', 'Booking Anda berhasil dibatalkan.');
    }

    /**
     * Tampilkan riwayat booking pelanggan.
     */
    public function history(): \Illuminate\View\View
    {
        $user = Auth::user();
        if (!$user) {
            return redirect()->route('login');
        }

        $bookings = Booking::where('user_id', $user->id)
                           ->orderBy('booking_date', 'desc')
                           ->orderBy('created_at', 'desc')
                           ->with('services')
                           ->get();

        return view('booking.history', [
            'bookings' => $bookings,
        ]);
    }
}