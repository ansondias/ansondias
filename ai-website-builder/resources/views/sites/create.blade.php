@extends('layouts.app')
@section('title', 'New website')

@section('content')
    <h1>Build a website</h1>
    <p class="muted">Give us any mix of the below. Only the business name is required.</p>

    <div class="rule">
        <strong>Our promise:</strong> we never invent facts. Everything on your site comes from what you give us or
        what we find about your business, and when sources disagree or we're unsure, we ask you.
    </div>

    <form method="post" action="{{ route('sites.store') }}" enctype="multipart/form-data" class="panel">
        @csrf
        <h2>1. The business</h2>
        <div class="grid2">
            <div>
                <label for="business_name">Business name *</label>
                <input type="text" id="business_name" name="business_name" value="{{ old('business_name') }}" required maxlength="150" placeholder="e.g. Sharma Sweets">
            </div>
            <div>
                <label for="city">City <span class="hint">(helps us find the right one)</span></label>
                <input type="text" id="city" name="city" value="{{ old('city') }}" maxlength="100" placeholder="e.g. Pune">
            </div>
        </div>

        <h2 style="margin-top:1.5rem">2. Links</h2>
        <label for="google_url">Google Business / Maps listing <span class="hint">(optional)</span></label>
        <input type="url" id="google_url" name="google_url" value="{{ old('google_url') }}" placeholder="https://maps.app.goo.gl/…">
        <label for="website_url">Existing website <span class="hint">(optional)</span></label>
        <input type="url" id="website_url" name="website_url" value="{{ old('website_url') }}" placeholder="https://…">
        <label for="social_links">Social media links <span class="hint">(one per line - Instagram, Facebook, Zomato, JustDial…)</span></label>
        <textarea id="social_links" name="social_links" placeholder="https://instagram.com/yourbusiness">{{ old('social_links') }}</textarea>

        <h2 style="margin-top:1.5rem">3. Files</h2>
        <label for="files">Price list, menu, brochure, photos <span class="hint">(PDF, DOCX, TXT, CSV, JPG, PNG, WEBP - up to 10 MB each)</span></label>
        <input type="file" id="files" name="files[]" multiple accept=".pdf,.docx,.txt,.csv,.md,.html,.jpg,.jpeg,.png,.webp,.gif">

        <h2 style="margin-top:1.5rem">4. Anything else?</h2>
        <label for="notes">Notes in your own words <span class="hint">(services, timings, what makes you different…)</span></label>
        <textarea id="notes" name="notes" placeholder="We are open Mon–Sat 9am–9pm. We do home delivery within 5 km.">{{ old('notes') }}</textarea>

        <div style="margin-top:1.5rem">
            <button class="btn btn-big" type="submit">Build my website →</button>
        </div>
    </form>
@endsection
