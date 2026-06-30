import BranchController from './BranchController'
import PackageController from './PackageController'
import ShopSettingController from './ShopSettingController'
import MemberController from './MemberController'
import PurchaseController from './PurchaseController'

const Admin = {
    BranchController: Object.assign(BranchController, BranchController),
    PackageController: Object.assign(PackageController, PackageController),
    ShopSettingController: Object.assign(ShopSettingController, ShopSettingController),
    MemberController: Object.assign(MemberController, MemberController),
    PurchaseController: Object.assign(PurchaseController, PurchaseController),
}

export default Admin