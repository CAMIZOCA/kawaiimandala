@extends('layouts.app')
@section('title', 'Iniciar sesión')
@section('content')
<div class="card login">
    <h1>Iniciar sesión</h1>
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        @error('email')<div class="err">{{ $message }}</div>@enderror
        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" required>
        <p><label style="font-weight:400"><input type="checkbox" name="remember" value="1"> Recordarme</label></p>
        <button class="btn" type="submit">Entrar</button>
    </form>
</div>
@endsection
