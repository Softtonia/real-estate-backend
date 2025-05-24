<form method="POST" action="{{ route('reset-passwords') }}">
    
    <input type="hidden" name="id" value="{{ $user->id }}">
    <input type="password" name="password" placeholder="New Password">
    <br/><br/>
    <input type="password" name="password_confirmation" placeholder="Confirm Password">
    <br/><br/>
    <input type="submit">
</form>
